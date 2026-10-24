<?php

namespace App\Modules\Ordering\Enums;

/**
 * Estado operacional do pedido (independente do pagamento).
 *
 * pending_acceptance → accepted → preparing → ready → out_for_delivery → completed
 *                   ↘ rejected            ↘ cancelled (de qualquer etapa ativa)
 * Retirada: ready → completed (pula out_for_delivery).
 */
enum OrderStatus: string
{
    case PendingAcceptance = 'pending_acceptance';
    case Accepted = 'accepted';
    case Preparing = 'preparing';
    case Ready = 'ready';
    case OutForDelivery = 'out_for_delivery';
    case Completed = 'completed';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    public function isFinal(): bool
    {
        return in_array($this, [self::Completed, self::Rejected, self::Cancelled], true);
    }

    /**
     * @return list<self>
     */
    public function nextFor(FulfillmentType $fulfillment): array
    {
        return match ($this) {
            self::PendingAcceptance => [self::Accepted, self::Rejected, self::Cancelled],
            self::Accepted => [self::Preparing, self::Cancelled],
            self::Preparing => [self::Ready, self::Cancelled],
            self::Ready => $fulfillment === FulfillmentType::Delivery
                ? [self::OutForDelivery, self::Cancelled]
                : [self::Completed, self::Cancelled],
            self::OutForDelivery => [self::Completed, self::Cancelled],
            self::Completed, self::Rejected, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $next, FulfillmentType $fulfillment): bool
    {
        return in_array($next, $this->nextFor($fulfillment), true);
    }

    public function label(): string
    {
        return match ($this) {
            self::PendingAcceptance => 'Aguardando confirmação',
            self::Accepted => 'Pedido confirmado',
            self::Preparing => 'Em preparo',
            self::Ready => 'Pronto',
            self::OutForDelivery => 'Saiu para entrega',
            self::Completed => 'Concluído',
            self::Rejected => 'Recusado',
            self::Cancelled => 'Cancelado',
        };
    }
}
