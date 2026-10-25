<?php

namespace App\Modules\Payments\Services;

use App\Modules\Ordering\Enums\CancellationReason;
use App\Modules\Ordering\Enums\OrderStatus;
use App\Modules\Ordering\Enums\PaymentMethod;
use App\Modules\Ordering\Enums\PaymentStatus;
use App\Modules\Ordering\Models\Order;
use App\Modules\Ordering\Services\OrderTransitions;
use App\Modules\Payments\Enums\PaymentAttemptStatus;
use App\Modules\Payments\Enums\Provider;
use App\Modules\Payments\Gateway\GatewayException;
use App\Modules\Payments\Gateway\PaymentGateway;
use App\Modules\Payments\Gateway\RemotePayment;
use App\Modules\Payments\Jobs\RefundOrderPayments;
use App\Modules\Payments\Models\IntegrationConnection;
use App\Modules\Payments\Models\Payment;
use App\Modules\Platform\Audit\AuditLogger;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Aplica o estado AUTORITATIVO do provedor (sempre lido pela API com o token
 * do restaurante — nunca o corpo do webhook nem o retorno do navegador) ao
 * pagamento e ao pedido, com ambos travados.
 *
 * Conferências antes de liberar o pedido: external_reference = nosso
 * public_id, conta recebedora = conta conectada, moeda BRL e valor exato.
 */
final class PaymentSync
{
    /** Tolerância após o vencimento antes de expirar (atraso de notificação). */
    public const EXPIRY_GRACE_SECONDS = 120;

    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly OrderTransitions $transitions,
        private readonly AuditLogger $audit,
    ) {}

    /** Busca o estado atual no provedor e aplica. */
    public function refresh(Payment $payment): Payment
    {
        $connection = $this->connection();

        // Pix tem um único pagamento remoto. No Checkout Pro o cliente pode
        // tentar vários cartões: até aprovar, procura pela referência.
        $byId = $payment->external_id !== null
            && ($payment->method === PaymentMethod::Pix || $payment->status->wasPaid());
        if ($byId) {
            return $this->apply($payment, $this->gateway->getPayment($connection, (string) $payment->external_id), $connection);
        }

        $remotes = $this->gateway->searchByReference($connection, $payment->public_id);
        $best = $this->pickBest($remotes);
        if ($best === null) {
            $payment->forceFill(['last_synced_at' => Carbon::now()])->save();

            return $payment;
        }

        return $this->apply($payment, $best, $connection);
    }

    /** Notificação do provedor: localiza o pagamento pela referência remota. */
    public function handleRemotePaymentId(string $remoteId): ?Payment
    {
        $connection = $this->connection();
        $remote = $this->gateway->getPayment($connection, $remoteId);

        $payment = $remote->externalReference === null ? null
            : Payment::query()->where('public_id', $remote->externalReference)->first();

        if ($payment === null) {
            // Pagamento de outra origem na conta do restaurante: não é nosso.
            Log::info('payments.notification_unmatched', ['remote_id' => $remote->id]);

            return null;
        }

        return $this->apply($payment, $remote, $connection);
    }

    public function apply(Payment $payment, RemotePayment $remote, IntegrationConnection $connection): Payment
    {
        return DB::transaction(function () use ($payment, $remote, $connection) {
            /** @var Payment $locked */
            $locked = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            /** @var Order $order */
            $order = Order::query()->whereKey($locked->order_id)->lockForUpdate()->firstOrFail();
            $now = Carbon::now();

            if ($remote->externalReference !== $locked->public_id) {
                $this->audit->record('payment.reference_mismatch', $locked, ['remote_id' => $remote->id]);

                return $locked;
            }

            // Outra tentativa do mesmo checkout depois de já pago: não rebaixa.
            if ($locked->external_id !== null && $locked->external_id !== $remote->id && $locked->status->wasPaid()) {
                if ($remote->status === 'approved') {
                    $this->audit->record('payment.duplicate_approval', $locked, ['remote_id' => $remote->id]);
                }

                return $locked;
            }

            $problem = $this->check($locked, $remote, $connection);
            if ($problem !== null) {
                $locked->forceFill([
                    'external_id' => $remote->id,
                    'status' => PaymentAttemptStatus::Mismatch,
                    'provider_status' => $remote->status,
                    'provider_status_detail' => $remote->statusDetail,
                    'last_error' => $problem,
                    'last_synced_at' => $now,
                ])->save();
                $this->audit->record('payment.mismatch', $locked, ['problem' => $problem, 'remote_id' => $remote->id]);
                Log::warning('payments.mismatch', ['payment' => $locked->public_id, 'problem' => $problem]);

                return $locked;
            }

            $next = $this->statusFor($remote);
            // GET é autoritativo, mas nunca "despaga" um pagamento já confirmado.
            if ($locked->status->wasPaid() && ! $next->wasPaid()) {
                $next = $locked->status;
            }

            $firstApproval = $next->wasPaid() && $locked->approved_at === null;

            $locked->forceFill([
                'external_id' => $remote->id,
                'status' => $next,
                'provider_status' => $remote->status,
                'provider_status_detail' => $remote->statusDetail,
                'amount_refunded' => Money::format($remote->refundedCents),
                'approved_at' => $firstApproval ? $now : $locked->approved_at,
                'last_error' => null,
                'last_synced_at' => $now,
            ])->save();

            if ($firstApproval) {
                $this->onApproved($locked, $order);
            }

            $this->syncOrderPaymentStatus($locked, $order);

            return $locked;
        });
    }

    /**
     * Vence um pagamento aberto cujo prazo passou (chamado pela reconciliação
     * DEPOIS de um refresh). Cancela o pedido se ele ainda espera pagamento.
     */
    public function expire(Payment $payment): Payment
    {
        return DB::transaction(function () use ($payment) {
            /** @var Payment $locked */
            $locked = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            /** @var Order $order */
            $order = Order::query()->whereKey($locked->order_id)->lockForUpdate()->firstOrFail();

            $expirable = in_array($locked->status, [PaymentAttemptStatus::Pending, PaymentAttemptStatus::Rejected, PaymentAttemptStatus::Cancelled], true);
            if (! $expirable || $locked->expires_at->copy()->addSeconds(self::EXPIRY_GRACE_SECONDS)->isFuture()) {
                return $locked;
            }

            $locked->forceFill(['status' => PaymentAttemptStatus::Expired])->save();

            if ($order->status === OrderStatus::AwaitingPayment) {
                $this->transitions->system($order, OrderStatus::Cancelled, CancellationReason::PaymentNotCompleted);
            }

            return $locked;
        });
    }

    private function onApproved(Payment $payment, Order $order): void
    {
        $this->audit->record('payment.approved', $payment, ['order' => $order->public_id, 'amount' => $payment->amount]);

        if ($order->status === OrderStatus::AwaitingPayment) {
            $order->payment_status = PaymentStatus::Paid;
            $this->transitions->system($order, OrderStatus::PendingAcceptance, note: 'Pagamento confirmado pelo Mercado Pago');

            return;
        }

        if ($order->status === OrderStatus::Cancelled || $order->status === OrderStatus::Rejected) {
            // Pagou depois do prazo/cancelamento: o pedido não será feito, devolve.
            $order->forceFill(['payment_status' => PaymentStatus::Paid])->save();
            RefundOrderPayments::dispatch($order->id, null)->afterCommit();
        }
    }

    private function syncOrderPaymentStatus(Payment $payment, Order $order): void
    {
        $status = match ($payment->status) {
            PaymentAttemptStatus::Approved => PaymentStatus::Paid,
            PaymentAttemptStatus::PartiallyRefunded => PaymentStatus::PartiallyRefunded,
            PaymentAttemptStatus::Refunded => PaymentStatus::Refunded,
            default => null,
        };

        if ($status !== null && $order->payment_status !== $status) {
            $order->forceFill(['payment_status' => $status])->save();
        }
    }

    private function check(Payment $payment, RemotePayment $remote, IntegrationConnection $connection): ?string
    {
        if ($connection->account_id !== null && $remote->collectorId !== null && $remote->collectorId !== $connection->account_id) {
            return 'collector';
        }
        if ($remote->currency !== null && $remote->currency !== 'BRL') {
            return 'currency';
        }
        if ($remote->amountCents !== Money::toCents($payment->amount)) {
            return 'amount';
        }

        return null;
    }

    private function statusFor(RemotePayment $remote): PaymentAttemptStatus
    {
        $status = PaymentAttemptStatus::fromMercadoPago($remote->status);

        if ($status === PaymentAttemptStatus::Approved && $remote->refundedCents > 0) {
            return $remote->refundedCents >= $remote->amountCents ? PaymentAttemptStatus::Refunded : PaymentAttemptStatus::PartiallyRefunded;
        }

        return $status;
    }

    /**
     * @param  list<RemotePayment>  $remotes
     */
    private function pickBest(array $remotes): ?RemotePayment
    {
        $rank = fn (RemotePayment $r) => match (PaymentAttemptStatus::fromMercadoPago($r->status)) {
            PaymentAttemptStatus::Approved, PaymentAttemptStatus::Refunded, PaymentAttemptStatus::ChargedBack => 3,
            PaymentAttemptStatus::InReview => 2,
            PaymentAttemptStatus::Pending => 1,
            default => 0,
        };

        $best = null;
        foreach ($remotes as $remote) {
            if ($best === null || $rank($remote) > $rank($best)) {
                $best = $remote;
            }
        }

        return $best;
    }

    private function connection(): IntegrationConnection
    {
        return IntegrationConnection::activeFor(Provider::MercadoPago)
            ?? throw new GatewayException('not_connected', null, 'Mercado Pago não está conectado.', 409, 'payments_not_connected');
    }
}
