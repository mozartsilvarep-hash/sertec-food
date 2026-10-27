<?php

namespace App\Modules\Cash\Enums;

use App\Modules\Ordering\Enums\PaymentMethod;

/** Forma de pagamento no caixa. Só "cash" mexe no dinheiro da gaveta. */
enum CashMethod: string
{
    case Cash = 'cash';
    case Pix = 'pix';
    case Debit = 'debit';
    case Credit = 'credit';
    case Card = 'card';
    case Online = 'online';
    case Other = 'other';

    /**
     * Formas aceitas no PDV (sem "pago pelo site" nem o cartão genérico).
     *
     * @return list<self>
     */
    public static function posCases(): array
    {
        return [self::Cash, self::Pix, self::Debit, self::Credit, self::Other];
    }

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Dinheiro',
            self::Pix => 'Pix',
            self::Debit => 'Cartão de débito',
            self::Credit => 'Cartão de crédito',
            self::Card => 'Cartão (maquininha)',
            self::Online => 'Pago pelo site',
            self::Other => 'Outro',
        };
    }

    public static function fromOrder(PaymentMethod $method): self
    {
        return match ($method) {
            PaymentMethod::Cash => self::Cash,
            PaymentMethod::CardOnDelivery => self::Card,
            PaymentMethod::Pix, PaymentMethod::OnlineCard => self::Online,
            PaymentMethod::Pos => self::Other,
        };
    }
}
