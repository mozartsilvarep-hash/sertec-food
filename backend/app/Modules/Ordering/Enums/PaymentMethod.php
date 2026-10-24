<?php

namespace App\Modules\Ordering\Enums;

enum PaymentMethod: string
{
    /** Dinheiro na entrega/retirada (pode pedir troco). */
    case Cash = 'cash';

    /** Cartão na maquininha, na entrega/retirada. */
    case CardOnDelivery = 'card_on_delivery';

    public function isOnDelivery(): bool
    {
        return true; // Sprint 3 acrescenta métodos online (Mercado Pago).
    }

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Dinheiro',
            self::CardOnDelivery => 'Cartão na entrega',
        };
    }
}
