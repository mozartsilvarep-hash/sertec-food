<?php

namespace App\Modules\Ordering\Services;

use App\Modules\Identity\Models\User;
use App\Modules\Ordering\Enums\CancellationReason;
use App\Modules\Ordering\Enums\OrderStatus;
use App\Modules\Ordering\Enums\PaymentStatus;
use App\Modules\Ordering\Models\Order;
use App\Modules\Ordering\Models\OrderStatusHistory;
use App\Modules\Platform\Audit\AuditLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Única porta para mudar o estado de um pedido. Valida a transição contra a
 * máquina de estados (OrderStatus::nextFor) com o pedido travado, grava o
 * histórico e ajusta o pagamento na entrega.
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

            $isCancel = in_array($next, [OrderStatus::Rejected, OrderStatus::Cancelled], true);
            $historyNote = null;
            if ($isCancel) {
                if ($reason === null) {
                    throw OrderingException::make('reason_required', 'Informe o motivo.');
                }
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

            if ($isCancel) {
                $locked->cancelled_at = $now;
                $locked->cancellation_reason = $reason->value;
                $locked->cancellation_note = $note;
                if ($locked->payment_status === PaymentStatus::Pending) {
                    $locked->payment_status = PaymentStatus::Voided;
                }
            }

            $locked->save();

            OrderStatusHistory::query()->create([
                'order_id' => $locked->id,
                'old_status' => $current,
                'new_status' => $next,
                'actor_user_id' => $actor->id,
                'note' => $historyNote,
            ]);

            $this->audit->record('order.status_changed', $locked, [
                'from' => $current->value,
                'to' => $next->value,
            ], actor: $actor);

            return $locked;
        });
    }
}
