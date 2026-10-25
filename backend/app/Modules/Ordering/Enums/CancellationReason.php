<?php

namespace App\Modules\Ordering\Enums;

/**
 * Motivos padronizados de recusa/cancelamento (mensagem clara ao cliente).
 */
enum CancellationReason: string
{
    case StoreClosed = 'store_closed';
    case OutOfStock = 'out_of_stock';
    case OutOfArea = 'out_of_area';
    case CustomerRequest = 'customer_request';
    case CustomerUnreachable = 'customer_unreachable';
    case Other = 'other';

    /** Automático: o pagamento online não foi concluído no prazo. */
    case PaymentNotCompleted = 'payment_not_completed';

    public function label(): string
    {
        return match ($this) {
            self::StoreClosed => 'Estabelecimento fechado',
            self::OutOfStock => 'Item indisponível',
            self::OutOfArea => 'Endereço fora da área de entrega',
            self::CustomerRequest => 'A pedido do cliente',
            self::CustomerUnreachable => 'Não conseguimos contato',
            self::Other => 'Outro motivo',
            self::PaymentNotCompleted => 'Pagamento não concluído',
        };
    }
}
