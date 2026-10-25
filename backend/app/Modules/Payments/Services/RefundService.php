<?php

namespace App\Modules\Payments\Services;

use App\Modules\Identity\Models\User;
use App\Modules\Ordering\Enums\PaymentStatus;
use App\Modules\Ordering\Models\Order;
use App\Modules\Ordering\Services\OrderingException;
use App\Modules\Payments\Enums\PaymentAttemptStatus;
use App\Modules\Payments\Enums\Provider;
use App\Modules\Payments\Enums\RefundStatus;
use App\Modules\Payments\Gateway\GatewayException;
use App\Modules\Payments\Gateway\PaymentGateway;
use App\Modules\Payments\Models\IntegrationConnection;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\Refund;
use App\Modules\Platform\Audit\AuditLogger;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Reembolso total ou parcial. O pagamento fica travado durante a chamada ao
 * provedor (evita dois reembolsos simultâneos somarem mais que o pago) e a
 * chave de idempotência é o public_id do reembolso.
 */
final class RefundService
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly AuditLogger $audit,
    ) {}

    public static function refundableCents(Payment $payment): int
    {
        if (! in_array($payment->status, [PaymentAttemptStatus::Approved, PaymentAttemptStatus::PartiallyRefunded], true)) {
            return 0;
        }

        return max(0, Money::toCents($payment->amount) - Money::toCents($payment->amount_refunded));
    }

    /** Pagamento online confirmado do pedido, se houver. */
    public static function paidPaymentFor(Order $order): ?Payment
    {
        return Payment::query()->where('order_id', $order->id)
            ->whereIn('status', [PaymentAttemptStatus::Approved, PaymentAttemptStatus::PartiallyRefunded])
            ->orderByDesc('id')->first();
    }

    /**
     * @param  int|null  $amountCents  null = tudo o que ainda pode ser devolvido
     */
    public function refund(Payment $payment, ?int $amountCents, ?User $actor, ?string $reason): Refund
    {
        $failure = null;

        $refund = DB::transaction(function () use ($payment, $amountCents, $actor, $reason, &$failure) {
            /** @var Payment $locked */
            $locked = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $available = self::refundableCents($locked);

            if ($available <= 0) {
                throw OrderingException::make('nothing_to_refund', 'Não há valor a reembolsar neste pagamento.', null, 409);
            }

            $amount = $amountCents ?? $available;
            if ($amount <= 0 || $amount > $available) {
                throw OrderingException::make('refund_amount_invalid', 'Valor acima do disponível para reembolso.', [
                    'available' => Money::format($available),
                ]);
            }

            $connection = IntegrationConnection::activeFor(Provider::MercadoPago)
                ?? throw new GatewayException('not_connected', null, 'Mercado Pago não está conectado.', 409, 'payments_not_connected');

            $refund = Refund::query()->create([
                'payment_id' => $locked->id,
                'amount' => Money::format($amount),
                'status' => RefundStatus::Pending,
                'reason' => $reason,
                'requested_by_user_id' => $actor?->id,
            ]);

            try {
                $remote = $this->gateway->refund(
                    $connection,
                    (string) $locked->external_id,
                    $amount === $available && Money::toCents($locked->amount_refunded) === 0 ? null : $amount,
                    $refund->public_id,
                );
            } catch (GatewayException $e) {
                // Grava a falha (commit) e só depois devolve o erro ao chamador.
                $refund->forceFill(['status' => RefundStatus::Failed, 'last_error' => mb_substr($e->reason, 0, 80)])->save();
                $failure = $e;

                return $refund;
            }

            $status = RefundStatus::fromMercadoPago($remote->status);
            $refund->forceFill(['external_id' => $remote->id ?: null, 'status' => $status])->save();

            if ($status !== RefundStatus::Rejected) {
                $refunded = Money::toCents($locked->amount_refunded) + $amount;
                $full = $refunded >= Money::toCents($locked->amount);
                $locked->forceFill([
                    'amount_refunded' => Money::format($refunded),
                    'status' => $full ? PaymentAttemptStatus::Refunded : PaymentAttemptStatus::PartiallyRefunded,
                ])->save();

                Order::query()->whereKey($locked->order_id)->lockForUpdate()->firstOrFail()
                    ->forceFill(['payment_status' => $full ? PaymentStatus::Refunded : PaymentStatus::PartiallyRefunded])->save();
            }

            $this->audit->record('payment.refunded', $locked, [
                'refund' => $refund->public_id,
                'amount' => Money::format($amount),
                'status' => $status->value,
            ], actor: $actor);

            return $refund;
        });

        if ($failure !== null) {
            throw $failure;
        }

        return $refund;
    }
}
