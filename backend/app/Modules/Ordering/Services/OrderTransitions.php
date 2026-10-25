<?php

namespace App\Modules\Ordering\Services;

use App\Modules\Identity\Models\User;
use App\Modules\Ordering\Enums\CancellationReason;
use App\Modules\Ordering\Enums\OrderStatus;
use App\Modules\Ordering\Enums\PaymentStatus;
use App\Modules\Ordering\Models\Order;
use App\Modules\Ordering\Models\OrderStatusHistory;
use App\Modules\Payments\Jobs\RefundOrderPayments;
use App\Modules\Platform\Audit\AuditLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Única porta para mudar o estado de um pedido. Valida a transição contra a
 * máquina de estados (OrderStatus::nextFor) com o pedido travado, grava o
 * histórico e ajusta o pagamento na entrega.
 *
 * `transition()` é o restaurante; `system()` é o próprio sistema reagindo ao
 * pagamento online (confirmado → entra no painel; prazo vencido → cancelado).
 */
final class OrderTransitions
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function transition(
        Order $order,
        OrderStatus $next,
        User $actor,
        ?int $etaMinutes = null,
        ?CancellationReason $reason = null,
        ?string $note = null,
    ): Order {
        return DB::transaction(function () use ($order, $next, $actor, $etaMinutes, $reason, $note) {
            /** @var Order $locked */
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $current = $locked->status;

            if (! $current->canTransitionTo($next, $locked->fulfillment_type)) {
                throw OrderingException::make('invalid_transition', "Não é possível mudar de \"{$current->label()}\" para \"{$next->label()}\".", [
                    'from' => $current->value,
                    'to' => $next->value,
                    'allowed' => array_map(fn (OrderStatus $s) => $s->value, $current->nextFor($locked->fulfillment_type)),
                ], 409);
            }

            if (in_array($next, [OrderStatus::Rejected, OrderStatus::Cancelled], true) && $reason === null) {
                throw OrderingException::make('reason_required', 'Informe o motivo.');
            }

            return $this->apply($locked, $next, $actor, $etaMinutes, $reason, $note);
        });
    }

    /**
     * Transição feita pelo sistema sobre um pedido JÁ travado pelo chamador
     * (PaymentSync/reconciliação, dentro da transação deles).
     */
    public function system(Order $locked, OrderStatus $next, ?CancellationReason $reason = null, ?string $note = null): Order
    {
        $allowed = match ($locked->status) {
            OrderStatus::AwaitingPayment => [OrderStatus::PendingAcceptance, OrderStatus::Cancelled],
            default => [],
        };

        if (! in_array($next, $allowed, true)) {
            throw OrderingException::make('invalid_transition', 'Transição automática inválida.', [
                'from' => $locked->status->value, 'to' => $next->value,
            ], 409);
        }

        return $this->apply($locked, $next, null, null, $reason, $note);
    }

    private function apply(Order $locked, OrderStatus $next, ?User $actor, ?int $etaMinutes, ?CancellationReason $reason, ?string $note): Order
    {
        $current = $locked->status;
        $isCancel = in_array($next, [OrderStatus::Rejected, OrderStatus::Cancelled], true);
        $historyNote = $note;
        if ($isCancel && $reason !== null) {
            $historyNote = trim($reason->label().($note ? ': '.$note : ''));
        }

        $now = Carbon::now();
        $locked->status = $next;

        if ($next === OrderStatus::Accepted) {
            $locked->accepted_at = $now;
            if ($etaMinutes !== null) {
                $locked->eta_minutes = $etaMinutes;
            }
        }

        if ($next === OrderStatus::Completed) {
            $locked->completed_at = $now;
            // Pagamento na entrega: recebido quando o pedido é concluído.
            if ($locked->payment_method->isOnDelivery() && $locked->payment_status === PaymentStatus::Pending) {
                $locked->payment_status = PaymentStatus::Paid;
            }
        }

        $refundOnline = false;
        if ($isCancel) {
            $locked->cancelled_at = $now;
            $locked->cancellation_reason = $reason?->value;
            $locked->cancellation_note = $actor !== null ? $note : null;
            if ($locked->payment_status === PaymentStatus::Pending) {
                $locked->payment_status = $locked->payment_method->isOnline() ? PaymentStatus::Expired : PaymentStatus::Voided;
            }
            // Pedido pago online e cancelado pelo restaurante: devolve o dinheiro.
            $refundOnline = $locked->payment_method->isOnline()
                && in_array($locked->payment_status, [PaymentStatus::Paid, PaymentStatus::PartiallyRefunded], true);
        }

        $locked->save();

        OrderStatusHistory::query()->create([
            'order_id' => $locked->id,
            'old_status' => $current,
            'new_status' => $next,
            'actor_user_id' => $actor?->id,
            'note' => $historyNote,
        ]);

        $this->audit->record('order.status_changed', $locked, [
            'from' => $current->value,
            'to' => $next->value,
            'by' => $actor === null ? 'system' : 'user',
        ], actor: $actor);

        if ($refundOnline) {
            RefundOrderPayments::dispatch($locked->id, $actor?->id)->afterCommit();
        }

        return $locked;
    }
}
