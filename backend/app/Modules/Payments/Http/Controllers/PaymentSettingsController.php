<?php

namespace App\Modules\Payments\Http\Controllers;

use App\Modules\Ordering\Services\OrderingException;
use App\Modules\Payments\Enums\Provider;
use App\Modules\Payments\Gateway\PaymentGateway;
use App\Modules\Payments\Models\IntegrationConnection;
use App\Modules\Platform\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Conta do Mercado Pago do restaurante. O token é validado na API do
 * provedor (/users/me) antes de ser gravado; a resposta nunca devolve
 * credenciais, só os 4 últimos caracteres para conferência.
 */
class PaymentSettingsController
{
    private const TOKEN = '/^(APP_USR|TEST)-[A-Za-z0-9_-]{20,250}$/';

    public function show(Request $request): JsonResponse
    {
        return new JsonResponse(['data' => self::present(IntegrationConnection::query()->where('provider', Provider::MercadoPago)->first(), $request)]);
    }

    public function update(Request $request, PaymentGateway $gateway, AuditLogger $audit): JsonResponse
    {
        $connection = IntegrationConnection::query()->where('provider', Provider::MercadoPago)->first();
        $data = $request->validate([
            // Vazio ao editar = mantém o token atual (o painel nunca o recebe de volta).
            'access_token' => [$connection?->credentials ? 'nullable' : 'required', 'string', 'regex:'.self::TOKEN],
            'webhook_secret' => ['nullable', 'string', 'min:16', 'max:128', 'regex:/^[A-Za-z0-9_-]+$/'],
            'remove_webhook_secret' => ['sometimes', 'boolean'],
        ], [
            'access_token.regex' => 'Cole o Access Token completo (começa com APP_USR- ou TEST-).',
            'webhook_secret.regex' => 'A assinatura secreta tem só letras e números.',
        ], ['access_token' => 'Access Token', 'webhook_secret' => 'assinatura secreta']);

        $token = $data['access_token'] ?? $connection?->accessToken();
        if (! is_string($token) || $token === '') {
            throw OrderingException::make('validation_failed', 'Informe o Access Token.');
        }

        $account = $gateway->account($token);
        if ($account->siteId !== null && $account->siteId !== 'MLB') {
            throw OrderingException::make('provider_account_not_brazil', 'Esta conta do Mercado Pago não é do Brasil.');
        }

        $secret = $request->boolean('remove_webhook_secret') ? null
            : ($data['webhook_secret'] ?? $connection?->webhookSecret());

        $connection ??= new IntegrationConnection(['provider' => Provider::MercadoPago]);
        $connection->fill([
            'credentials' => ['access_token' => $token, 'webhook_secret' => $secret],
            'mode' => str_starts_with($token, 'TEST-') ? 'test' : 'live',
            'status' => IntegrationConnection::ACTIVE,
            'account_id' => $account->id !== '' ? $account->id : null,
            'account_label' => mb_substr($account->label, 0, 120),
            'token_hint' => substr($token, -4),
            'verified_at' => Carbon::now(),
        ])->save();

        $audit->record('payments.connected', null, [
            'provider' => 'mercadopago', 'mode' => $connection->mode, 'account' => $connection->account_id,
            'webhook_secret' => $secret !== null,
        ]);

        return new JsonResponse(['data' => self::present($connection, $request)]);
    }

    public function destroy(Request $request, AuditLogger $audit): JsonResponse
    {
        $connection = IntegrationConnection::query()->where('provider', Provider::MercadoPago)->first();
        if ($connection !== null) {
            // Apaga o segredo de verdade; o registro fica para o histórico.
            $connection->fill(['credentials' => null, 'status' => IntegrationConnection::DISABLED, 'token_hint' => null])->save();
            $audit->record('payments.disconnected', null, ['provider' => 'mercadopago']);
        }

        return new JsonResponse(['data' => self::present($connection, $request)]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(?IntegrationConnection $connection, Request $request): array
    {
        $active = $connection !== null && $connection->status === IntegrationConnection::ACTIVE;

        return [
            'provider' => 'mercadopago',
            'connected' => $active,
            'mode' => $active ? $connection->mode : null,
            'account_label' => $active ? $connection->account_label : null,
            'account_id' => $active ? $connection->account_id : null,
            'token_hint' => $active ? $connection->token_hint : null,
            'has_webhook_secret' => $active && $connection->webhookSecret() !== null,
            'verified_at' => $active ? $connection->verified_at?->toIso8601String() : null,
            // Endereço a cadastrar em "Suas integrações > Webhooks" (evento Pagamentos).
            'webhook_url' => 'https://'.$request->getHost().'/api/v1/webhooks/mercadopago',
        ];
    }
}
