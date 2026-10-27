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

    /** Venda no PDV do restaurante: já paga no balcão (formas em order_payments). */
    case Pos = 'pos';

    public function isOnDelivery(): bool
    {
        return in_array($this, [self::Cash, self::CardOnDelivery], true);
    }

    public function isOnline(): bool
    {
        return in_array($this, [self::Pix, self::OnlineCard], true);
    }

    /** Formas que o cliente pode escolher no cardápio. */
    public function isStorefront(): bool
    {
        return $this !== self::Pos;
    }

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Dinheiro',
            self::CardOnDelivery => 'Cartão na entrega',
            self::Pix => 'Pix',
            self::OnlineCard => 'Cartão pelo site',
            self::Pos => 'Pago no balcão',
        };
    }
}
