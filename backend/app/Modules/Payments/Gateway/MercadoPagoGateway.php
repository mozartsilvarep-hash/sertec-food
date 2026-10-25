<?php

namespace App\Modules\Payments\Gateway;

use App\Modules\Payments\Models\IntegrationConnection;
use App\Support\Money;
use Carbon\CarbonImmutable;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cliente HTTP mínimo da API do Mercado Pago (sem SDK: menos dependências e
 * controle total do que é logado). Endpoints conforme o SDK oficial:
 *   GET  /users/me                       — dono do token
 *   POST /v1/payments                    — Pix (payment_method_id=pix)
 *   POST /checkout/preferences           — Checkout Pro (cartão)
 *   GET  /v1/payments/{id}               — status autoritativo
 *   GET  /v1/payments/search             — por external_reference
 *   POST /v1/payments/{id}/refunds       — reembolso total/parcial
 * Toda criação envia X-Idempotency-Key.
 */
final class MercadoPagoGateway implements PaymentGateway
{
    public function account(string $accessToken): AccountInfo
    {
        $body = $this->send($this->client($accessToken)->get('/users/me'), 'users_me');

        return new AccountInfo(
            id: (string) ($body['id'] ?? ''),
            label: (string) ($body['nickname'] ?? $body['email'] ?? 'Conta Mercado Pago'),
            siteId: isset($body['site_id']) ? (string) $body['site_id'] : null,
        );
    }

    public function createPix(IntegrationConnection $connection, array $data, string $idempotencyKey): RemotePayment
    {
        $payload = array_filter([
            'transaction_amount' => (float) Money::format($data['amount_cents']),
            'description' => mb_substr($data['description'], 0, 120),
            'payment_method_id' => 'pix',
            'external_reference' => $data['reference'],
            'date_of_expiration' => $data['expires_at']->format('Y-m-d\TH:i:s.vP'),
            'notification_url' => $data['notification_url'],
            'payer' => ['email' => $data['payer_email'], 'first_name' => mb_substr($data['payer_name'], 0, 60)],
        ], fn ($v) => $v !== null);

        $body = $this->send(
            $this->client($connection->accessToken())->withHeaders(['X-Idempotency-Key' => $idempotencyKey])->post('/v1/payments', $payload),
            'create_pix',
        );

        return $this->toPayment($body);
    }

    public function createCheckout(IntegrationConnection $connection, array $data, string $idempotencyKey): array
    {
        $payload = array_filter([
            'items' => [[
                'id' => $data['reference'],
                'title' => mb_substr($data['title'], 0, 120),
                'quantity' => 1,
                'currency_id' => 'BRL',
                'unit_price' => (float) Money::format($data['amount_cents']),
            ]],
            'payer' => array_filter(['name' => mb_substr($data['payer_name'], 0, 60), 'email' => $data['payer_email']]),
            'external_reference' => $data['reference'],
            'notification_url' => $data['notification_url'],
            'back_urls' => $data['back_url'] === null ? null : [
                'success' => $data['back_url'], 'pending' => $data['back_url'], 'failure' => $data['back_url'],
            ],
            'auto_return' => $data['back_url'] === null ? null : 'approved',
            // Só cartão: boleto/lotérica demoram dias e não servem para delivery.
            'payment_methods' => ['excluded_payment_types' => [['id' => 'ticket'], ['id' => 'atm'], ['id' => 'bank_transfer']]],
            'expires' => true,
            'expiration_date_to' => $data['expires_at']->format('Y-m-d\TH:i:s.vP'),
        ], fn ($v) => $v !== null);

        $body = $this->send(
            $this->client($connection->accessToken())->withHeaders(['X-Idempotency-Key' => $idempotencyKey])->post('/checkout/preferences', $payload),
            'create_preference',
        );

        $url = $connection->isTest() && ! empty($body['sandbox_init_point']) ? $body['sandbox_init_point'] : ($body['init_point'] ?? null);
        if (! is_string($url) || ! str_starts_with($url, 'https://') || empty($body['id'])) {
            throw new GatewayException('invalid_preference_response');
        }

        return ['preference_id' => (string) $body['id'], 'url' => $url];
    }

    public function getPayment(IntegrationConnection $connection, string $paymentId): RemotePayment
    {
        if (! preg_match('/^\d{1,30}$/', $paymentId)) {
            throw new GatewayException('invalid_payment_id', null, 'Pagamento inválido.', 422, 'invalid_payment_id');
        }

        return $this->toPayment($this->send($this->client($connection->accessToken())->get('/v1/payments/'.$paymentId), 'get_payment'));
    }

    public function searchByReference(IntegrationConnection $connection, string $reference): array
    {
        $body = $this->send($this->client($connection->accessToken())->get('/v1/payments/search', [
            'external_reference' => $reference,
            'sort' => 'date_created',
            'criteria' => 'desc',
            'limit' => 20,
        ]), 'search_payments');

        $results = is_array($body['results'] ?? null) ? $body['results'] : [];

        return array_values(array_map(fn (array $p) => $this->toPayment($p), array_filter($results, 'is_array')));
    }

    public function refund(IntegrationConnection $connection, string $paymentId, ?int $amountCents, string $idempotencyKey): RemoteRefund
    {
        $client = $this->client($connection->accessToken())->withHeaders(['X-Idempotency-Key' => $idempotencyKey]);
        $path = '/v1/payments/'.rawurlencode($paymentId).'/refunds';
        $response = $amountCents === null
            ? $client->post($path)
            : $client->post($path, ['amount' => (float) Money::format($amountCents)]);

        $body = $this->send($response, 'refund');

        return new RemoteRefund(
            id: (string) ($body['id'] ?? ''),
            status: (string) ($body['status'] ?? 'pending'),
            amountCents: Money::toCents((string) ($body['amount'] ?? '0')),
        );
    }

    private function client(string $token): PendingRequest
    {
        return Http::baseUrl((string) config('services.mercadopago.base_url'))
            ->withToken($token)
            ->acceptJson()
            ->asJson()
            ->connectTimeout(5)
            ->timeout((int) config('services.mercadopago.timeout', 10));
    }

    /**
     * @param  Response|PromiseInterface  $response
     * @return array<string, mixed>
     */
    private function send(mixed $response, string $operation): array
    {
        if (! $response instanceof Response) {
            throw new GatewayException('unexpected_response');
        }

        if ($response->status() === 401 || $response->status() === 403) {
            Log::warning('mercadopago.unauthorized', ['operation' => $operation, 'status' => $response->status()]);
            throw GatewayException::invalidCredentials();
        }

        if ($response->failed()) {
            // Só o status e o código de erro do provedor; nunca o corpo inteiro.
            $error = $response->json('error');
            Log::warning('mercadopago.request_failed', [
                'operation' => $operation,
                'status' => $response->status(),
                'error' => is_string($error) ? mb_substr($error, 0, 60) : null,
            ]);
            throw new GatewayException($operation.'_failed', $response->status());
        }

        $json = $response->json();

        return is_array($json) ? $json : throw new GatewayException('invalid_json');
    }

    /**
     * @param  array<string, mixed>  $p
     */
    private function toPayment(array $p): RemotePayment
    {
        if (! isset($p['id'], $p['status'])) {
            throw new GatewayException('invalid_payment_response');
        }

        /** @var array<string, mixed> $tx */
        $tx = is_array($p['point_of_interaction']['transaction_data'] ?? null) ? $p['point_of_interaction']['transaction_data'] : [];
        $expires = $p['date_of_expiration'] ?? null;

        return new RemotePayment(
            id: (string) $p['id'],
            status: (string) $p['status'],
            statusDetail: isset($p['status_detail']) ? mb_substr((string) $p['status_detail'], 0, 80) : null,
            externalReference: isset($p['external_reference']) ? (string) $p['external_reference'] : null,
            amountCents: Money::toCents(number_format((float) ($p['transaction_amount'] ?? 0), 2, '.', '')),
            refundedCents: Money::toCents(number_format((float) ($p['transaction_amount_refunded'] ?? 0), 2, '.', '')),
            currency: isset($p['currency_id']) ? (string) $p['currency_id'] : null,
            collectorId: isset($p['collector_id']) ? (string) $p['collector_id'] : null,
            liveMode: isset($p['live_mode']) ? (bool) $p['live_mode'] : null,
            pixQrCode: isset($tx['qr_code']) ? (string) $tx['qr_code'] : null,
            pixQrBase64: isset($tx['qr_code_base64']) && preg_match('#^[A-Za-z0-9+/=\s]+$#', (string) $tx['qr_code_base64']) ? (string) $tx['qr_code_base64'] : null,
            ticketUrl: isset($tx['ticket_url']) && str_starts_with((string) $tx['ticket_url'], 'https://') ? (string) $tx['ticket_url'] : null,
            expiresAt: is_string($expires) ? CarbonImmutable::parse($expires) : null,
        );
    }
}
