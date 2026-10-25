<?php

namespace Tests\Support;

use App\Modules\Payments\Enums\Provider;
use App\Modules\Payments\Models\IntegrationConnection;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Mercado Pago simulado em memória (Http::fake), com estado por conta: um
 * token só enxerga os pagamentos criados com ele — como na API real.
 * Implementa só o que o gateway usa.
 */
final class FakeMercadoPago
{
    /** @var array<string, array<string, mixed>> id => pagamento */
    public array $payments = [];

    /** @var list<array{method: string, path: string, token: string, idempotency: string|null, body: array<string, mixed>}> */
    public array $requests = [];

    /** @var array<string, int> operação => status HTTP a devolver uma vez */
    public array $failNext = [];

    /** @var array<string, string> token => collector_id */
    public array $accounts = [];

    /** @var array<string, string> chave de idempotência => id */
    private array $idempotency = [];

    private int $nextId = 9_000_000_001;

    public static function install(): self
    {
        $fake = new self;
        Http::preventStrayRequests();
        Http::fake(['https://api.mercadopago.com/*' => fn (Request $request) => $fake->handle($request)]);
        app()->instance(self::class, $fake);

        return $fake;
    }

    public function account(string $token, string $collectorId): string
    {
        $this->accounts[$token] = $collectorId;

        return $token;
    }

    /** Conecta o tenant direto no banco (sem passar pelo painel). */
    public function connect(Tenant $tenant, ?string $webhookSecret = 'segredo-webhook-de-teste-123456', ?string $token = null): IntegrationConnection
    {
        $token ??= 'TEST-'.Str::random(30).'-'.$tenant->slug;
        $collector = (string) (100000 + $tenant->id);
        $this->account($token, $collector);

        return inTenant($tenant, fn () => IntegrationConnection::query()->create([
            'provider' => Provider::MercadoPago,
            'credentials' => ['access_token' => $token, 'webhook_secret' => $webhookSecret],
            'mode' => 'test',
            'status' => IntegrationConnection::ACTIVE,
            'account_id' => $collector,
            'account_label' => 'LOJA '.$tenant->slug,
            'token_hint' => substr($token, -4),
            'verified_at' => now(),
        ]));
    }

    /**
     * Simula o cliente pagando (ou o banco recusando) fora do nosso sistema.
     *
     * @param  array<string, mixed>  $overrides
     */
    public function set(string $id, string $status, array $overrides = []): void
    {
        $this->payments[$id] = array_replace($this->payments[$id], ['status' => $status], $overrides);
    }

    /** Pagamento de cartão feito no Checkout Pro (o cliente saiu do nosso site). */
    public function cardPayment(string $token, string $reference, string $status, float $amount): string
    {
        $id = (string) $this->nextId++;
        $this->payments[$id] = $this->paymentBody($id, $token, [
            'status' => $status,
            'external_reference' => $reference,
            'transaction_amount' => $amount,
            'payment_method_id' => 'visa',
            'payment_type_id' => 'credit_card',
        ]);

        return $id;
    }

    public function lastPaymentId(): string
    {
        return (string) array_key_last($this->payments);
    }

    /** @return list<array{method: string, path: string, token: string, idempotency: string|null, body: array<string, mixed>}> */
    public function requestsTo(string $path): array
    {
        return array_values(array_filter($this->requests, fn ($r) => str_starts_with($r['path'], $path)));
    }

    private function handle(Request $request): mixed
    {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        $token = (string) preg_replace('/^Bearer /', '', $request->header('Authorization')[0] ?? '');
        $body = $request->data();
        $this->requests[] = [
            'method' => $request->method(),
            'path' => $path,
            'token' => $token,
            'idempotency' => $request->header('X-Idempotency-Key')[0] ?? null,
            'body' => is_array($body) ? $body : [],
        ];

        $op = $request->method().' '.preg_replace('/\d{6,}/', '{id}', $path);
        if (isset($this->failNext[$op])) {
            $status = $this->failNext[$op];
            unset($this->failNext[$op]);

            return Http::response(['message' => 'falha simulada', 'error' => 'internal_error'], $status);
        }

        if (! isset($this->accounts[$token])) {
            return Http::response(['message' => 'invalid access token', 'error' => 'unauthorized'], 401);
        }

        $key = $request->header('X-Idempotency-Key')[0] ?? null;

        return match (true) {
            $op === 'GET /users/me' => Http::response(['id' => (int) $this->accounts[$token], 'nickname' => 'LOJA_TESTE', 'site_id' => 'MLB']),
            $op === 'POST /v1/payments' => $this->createPix($token, $body, $key),
            $op === 'POST /checkout/preferences' => Http::response([
                'id' => '123-pref-'.substr((string) $key, -6),
                'init_point' => 'https://www.mercadopago.com.br/checkout/v1/redirect?pref_id=x',
                'sandbox_init_point' => 'https://sandbox.mercadopago.com.br/checkout/v1/redirect?pref_id=x',
            ], 201),
            $op === 'GET /v1/payments/search' => $this->search($token, $request),
            $op === 'GET /v1/payments/{id}' => $this->show($token, basename($path)),
            $op === 'POST /v1/payments/{id}/refunds' => $this->refund($token, (string) explode('/', $path)[3], $body, $key),
            default => Http::response(['message' => 'not found'], 404),
        };
    }

    /** @param  array<string, mixed>  $body */
    private function createPix(string $token, array $body, ?string $key): mixed
    {
        if ($key !== null && isset($this->idempotency[$key])) {
            return Http::response($this->payments[$this->idempotency[$key]], 201);
        }

        $id = (string) $this->nextId++;
        $this->payments[$id] = $this->paymentBody($id, $token, [
            'status' => 'pending',
            'status_detail' => 'pending_waiting_transfer',
            'external_reference' => $body['external_reference'] ?? null,
            'transaction_amount' => $body['transaction_amount'] ?? 0,
            'date_of_expiration' => $body['date_of_expiration'] ?? null,
            'payment_method_id' => 'pix',
            'payment_type_id' => 'bank_transfer',
            'point_of_interaction' => ['type' => 'PIX', 'transaction_data' => [
                'qr_code' => '00020126580014br.gov.bcb.pix0136'.$id.'5204000053039865802BR',
                'qr_code_base64' => base64_encode('png-'.$id),
                'ticket_url' => 'https://www.mercadopago.com.br/payments/'.$id.'/ticket',
            ]],
        ]);
        if ($key !== null) {
            $this->idempotency[$key] = $id;
        }

        return Http::response($this->payments[$id], 201);
    }

    private function show(string $token, string $id): mixed
    {
        $payment = $this->payments[$id] ?? null;
        if ($payment === null || $payment['_token'] !== $token) {
            return Http::response(['message' => 'Payment not found', 'error' => 'not_found'], 404);
        }

        return Http::response($payment);
    }

    private function search(string $token, Request $request): mixed
    {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $results = array_values(array_filter($this->payments, fn ($p) => $p['_token'] === $token && $p['external_reference'] === ($query['external_reference'] ?? null)));

        return Http::response(['paging' => ['total' => count($results)], 'results' => array_reverse($results)]);
    }

    /** @param  array<string, mixed>  $body */
    private function refund(string $token, string $id, array $body, ?string $key): mixed
    {
        $payment = $this->payments[$id] ?? null;
        if ($payment === null || $payment['_token'] !== $token) {
            return Http::response(['message' => 'not found'], 404);
        }
        if ($key !== null && isset($this->idempotency['r'.$key])) {
            return Http::response(['id' => (int) $this->idempotency['r'.$key], 'status' => 'approved', 'amount' => $body['amount'] ?? $payment['transaction_amount']], 201);
        }

        $amount = (float) ($body['amount'] ?? ($payment['transaction_amount'] - $payment['transaction_amount_refunded']));
        $refunded = round($payment['transaction_amount_refunded'] + $amount, 2);
        $this->payments[$id]['transaction_amount_refunded'] = $refunded;
        $this->payments[$id]['status'] = $refunded >= $payment['transaction_amount'] ? 'refunded' : 'approved';
        $refundId = $this->nextId++;
        if ($key !== null) {
            $this->idempotency['r'.$key] = (string) $refundId;
        }

        return Http::response(['id' => $refundId, 'payment_id' => (int) $id, 'amount' => $amount, 'status' => 'approved'], 201);
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    private function paymentBody(string $id, string $token, array $fields): array
    {
        return array_replace([
            'id' => (int) $id,
            '_token' => $token,
            'status' => 'pending',
            'status_detail' => null,
            'currency_id' => 'BRL',
            'collector_id' => (int) $this->accounts[$token],
            'live_mode' => false,
            'transaction_amount_refunded' => 0,
        ], $fields);
    }
}
