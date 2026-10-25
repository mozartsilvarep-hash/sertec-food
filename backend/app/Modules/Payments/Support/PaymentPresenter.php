<?php

namespace App\Modules\Payments\Support;

use App\Modules\Ordering\Models\Order;
use App\Modules\Payments\Enums\PaymentAttemptStatus;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\Refund;
use App\Modules\Payments\Services\RefundService;
use App\Support\Money;

/**
 * Blocos de pagamento online para o cliente (acompanhamento) e o restaurante.
 * Requer `payments` (e `payments.refunds` no painel) carregados.
 */
final class PaymentPresenter
{
    /**
     * O que o cliente precisa para pagar. Nada de IDs do provedor nem status internos.
     *
     * @return array<string, mixed>|null
     */
    public static function public(Order $order): ?array
    {
        if (! $order->payment_method->isOnline()) {
            return null;
        }

        /** @var Payment|null $payment */
        $payment = $order->payments->last();
        if ($payment === null) {
            return null;
        }

        $payable = $payment->status->isOpen() && $payment->expires_at->isFuture();

        return [
            'status' => $payment->status->value,
            'amount' => $payment->amount,
            'expires_at' => $payment->expires_at->toIso8601String(),
            'payable' => $payable,
            // Falhou ao gerar a cobrança (ex.: rede): o site oferece "tentar de novo".
            'needs_start' => $payable && ! $payment->isStarted(),
            'rejected' => $payment->status === PaymentAttemptStatus::Rejected,
            'pix' => $payable && $payment->pix_qr_code !== null ? [
                'qr_code' => $payment->pix_qr_code,
                'qr_code_base64' => $payment->pix_qr_base64,
                'ticket_url' => $payment->ticket_url,
            ] : null,
            'checkout_url' => $payable ? $payment->checkout_url : null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function merchant(Order $order): ?array
    {
        if (! $order->payment_method->isOnline()) {
            return null;
        }

        /** @var Payment|null $payment */
        $payment = $order->payments->last();

        return [
            'status' => $payment?->status->value,
            'amount' => $payment?->amount,
            'amount_refunded' => $payment?->amount_refunded,
            'refundable' => $payment === null ? '0.00' : Money::format(RefundService::refundableCents($payment)),
            'approved_at' => $payment?->approved_at?->toIso8601String(),
            'provider_reference' => $payment?->external_id,
            'attempts' => $order->payments->map(fn (Payment $p) => [
                'id' => $p->public_id,
                'status' => $p->status->value,
                'provider_status' => $p->provider_status,
                'provider_status_detail' => $p->provider_status_detail,
                'created_at' => $p->created_at->toIso8601String(),
            ])->values(),
            'refunds' => $order->payments->flatMap(fn (Payment $p) => $p->refunds->map(fn (Refund $r) => [
                'id' => $r->public_id,
                'amount' => $r->amount,
                'status' => $r->status->value,
                'reason' => $r->reason,
                'created_at' => $r->created_at->toIso8601String(),
            ]))->values(),
        ];
    }
}
