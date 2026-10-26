<?php

namespace App\Modules\Ordering\Enums;

/**
 * Estado de pagamento. Pagamento na entrega fica "pending" até o pedido ser
 * concluído. Online (Sprint 3) só muda por webhook/reconciliação — nunca
 * pelo retorno do navegador.
 */
enum PaymentStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Failed = 'failed';
    case Expired = 'expired';
    case Refunded = 'refunded';
    case PartiallyRefunded = 'partially_refunded';
    case Voided = 'voided';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendente',
            self::Paid => 'Pago',
            self::Failed => 'Falhou',
            self::Expired => 'Expirado',
            self::Refunded => 'Reembolsado',
            self::PartiallyRefunded => 'Reembolsado em parte',
            self::Voided => 'Anulado',
        };
    }
}
