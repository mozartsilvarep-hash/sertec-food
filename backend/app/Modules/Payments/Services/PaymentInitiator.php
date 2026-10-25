<?php

namespace App\Modules\Payments\Services;

use App\Modules\Ordering\Enums\PaymentMethod;
use App\Modules\Ordering\Models\Customer;
use App\Modules\Ordering\Models\Order;
use App\Modules\Payments\Enums\PaymentAttemptStatus;
use App\Modules\Payments\Enums\Provider;
use App\Modules\Payments\Gateway\GatewayException;
use App\Modules\Payments\Gateway\PaymentGateway;
use App\Modules\Payments\Models\IntegrationConnection;
use App\Modules\Payments\Models\Payment;
use App\Modules\Tenancy\TenantContext;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * Cria a cobrança no provedor para um pagamento já gravado (fora da
 * transação do checkout). Idempotente: a chave enviada é o public_id do
 * pagamento, então repetir depois de uma falha de rede não cobra duas vezes.
 */
final class PaymentInitiator
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly TenantContext $context,
    ) {}

    /** Cria a linha `payments` (dentro da transação do checkout). */
    public static function open(Order $order): Payment
    {
        $minutes = (int) config('services.mercadopago.payment_window_minutes', 30);

        return Payment::query()->create([
            'order_id' => $order->id,
            'provider' => Provider::MercadoPago,
            'method' => $order->payment_method,
            'amount' => $order->total,
            'status' => PaymentAttemptStatus::Pending,
            'expires_at' => CarbonImmutable::now()->addMinutes($minutes),
        ]);
    }

    public function start(Payment $payment, Request $request): Payment
    {
        if ($payment->isStarted() || $payment->status !== PaymentAttemptStatus::Pending) {
            return $payment;
        }

        $connection = IntegrationConnection::activeFor(Provider::MercadoPago)
            ?? throw new GatewayException('not_connected', null, 'Pagamento online indisponível neste estabelecimento.', 409, 'payments_not_connected');

        /** @var Order $order */
        $order = $payment->order;
        $tenant = $this->context->get();
        $customerEmail = $order->customer_id === null ? null : Customer::query()->whereKey($order->customer_id)->value('email');
        $expiresAt = CarbonImmutable::instance($payment->expires_at);
        $base = $request->isSecure() ? 'https://'.$request->getHost() : null;
        $notificationUrl = $base === null ? null : $base.'/api/v1/webhooks/mercadopago?source_news=webhooks';
        $title = "Pedido #{$order->order_number} — {$tenant->name}";

        try {
            if ($payment->method === PaymentMethod::Pix) {
                $remote = $this->gateway->createPix($connection, [
                    'amount_cents' => Money::toCents($payment->amount),
                    'description' => $title,
                    'reference' => $payment->public_id,
                    'payer_email' => (string) $customerEmail,
                    'payer_name' => $order->customer_name,
                    'expires_at' => $expiresAt,
                    'notification_url' => $notificationUrl,
                ], $payment->public_id);

                if ($remote->pixQrCode === null) {
                    throw new GatewayException('pix_without_qr');
                }

                $payment->forceFill([
                    'external_id' => $remote->id,
                    'provider_status' => $remote->status,
                    'provider_status_detail' => $remote->statusDetail,
                    'pix_qr_code' => $remote->pixQrCode,
                    'pix_qr_base64' => $remote->pixQrBase64,
                    'ticket_url' => $remote->ticketUrl,
                    'last_error' => null,
                ])->save();
            } else {
                $checkout = $this->gateway->createCheckout($connection, [
                    'amount_cents' => Money::toCents($payment->amount),
                    'title' => $title,
                    'reference' => $payment->public_id,
                    'payer_name' => $order->customer_name,
                    'payer_email' => is_string($customerEmail) ? $customerEmail : null,
                    'expires_at' => $expiresAt,
                    'notification_url' => $notificationUrl,
                    // Sem token de acompanhamento na URL: o site acha o pedido no aparelho.
                    'back_url' => $base === null ? null : $base.'/pedido/retorno',
                ], $payment->public_id);

                $payment->forceFill([
                    'preference_id' => $checkout['preference_id'],
                    'checkout_url' => $checkout['url'],
                    'last_error' => null,
                ])->save();
            }
        } catch (GatewayException $e) {
            $payment->forceFill(['last_error' => mb_substr($e->reason, 0, 80)])->save();
            throw $e;
        }

        return $payment;
    }
}
