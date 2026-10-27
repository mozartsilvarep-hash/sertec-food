<?php

namespace App\Modules\Ordering\Enums;

enum FulfillmentType: string
{
    case Delivery = 'delivery';
    case Pickup = 'pickup';

    /** Venda no balcão (PDV): o cliente leva na hora ou espera no local. */
    case Counter = 'counter';

    public function label(): string
    {
        return match ($this) {
            self::Delivery => 'Entrega',
            self::Pickup => 'Retirada',
            self::Counter => 'Balcão',
        };
    }
}
