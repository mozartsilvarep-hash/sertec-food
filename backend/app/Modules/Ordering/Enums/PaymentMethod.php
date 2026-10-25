<?php

namespace App\Modules\Ordering\Enums;

enum PaymentMethod: string
{
    /** Dinheiro na entrega/retirada (pode pedir troco). */
    case Cash = 'cash';

    /** Cartão na maquininha, na entrega/retirada. */
    case CardOnDelivery = 'card_on_delivery';

    /** Pix pelo Mercado Pago, pago no site (QR Code / copia e cola). */
    case Pix = 'pix';

    /** Cartão pelo Checkout Pro do Mercado Pago (dados do cartão nunca passam por nós). */
    case OnlineCard = 'online_card';

    public function isOnDelivery(): bool
    {
        return in_array($this, [self::Cash, self::CardOnDelivery], true);
    }

    public function isOnline(): bool
    {
        return ! $this->isOnDelivery();
    }

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Dinheiro',
            self::CardOnDelivery => 'Cartão na entrega',
            self::Pix => 'Pix',
            self::OnlineCard => 'Cartão pelo site',
        };
    }
}
