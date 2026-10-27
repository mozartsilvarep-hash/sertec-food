<?php

namespace App\Modules\Platform\Billing;

use App\Modules\Payments\Enums\Provider;
use App\Modules\Payments\Models\IntegrationConnection;
use App\Modules\Platform\Models\PlatformSetting;

/**
 * Conta Mercado Pago da SerTec, onde os restaurantes pagam a mensalidade.
 * O token é cadastrado no painel SerTec, guardado criptografado em
 * platform_settings e nunca devolvido nem logado.
 */
final class PlatformMercadoPago
{
    public const KEY = 'billing.mercadopago';

    /** @return array<string, mixed>|null */
    public static function settings(): ?array
    {
        return PlatformSetting::read(self::KEY);
    }

    public static function configured(): bool
    {
        return is_string(self::settings()['access_token'] ?? null);
    }

    public static function webhookSecret(): ?string
    {
        $s = self::settings()['webhook_secret'] ?? null;

        return is_string($s) && $s !== '' ? $s : null;
    }

    /**
     * Conexão em memória (não gravada) para reaproveitar o PaymentGateway.
     */
    public static function connection(): ?IntegrationConnection
    {
        $s = self::settings();
        if (! is_string($s['access_token'] ?? null)) {
            return null;
        }

        return (new IntegrationConnection)->forceFill([
            'provider' => Provider::MercadoPago,
            'credentials' => ['access_token' => $s['access_token'], 'webhook_secret' => $s['webhook_secret'] ?? null],
            'mode' => $s['mode'] ?? 'live',
            'status' => IntegrationConnection::ACTIVE,
            'account_id' => $s['account_id'] ?? null,
        ]);
    }

    public static function notificationUrl(): string
    {
        return 'https://'.config('tenancy.admin_host').'/api/v1/billing/webhooks/mercadopago?source_news=webhooks';
    }
}
