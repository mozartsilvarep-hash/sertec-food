<?php

namespace App\Modules\Catalog\Stock;

enum StockMovementType: string
{
    case Sale = 'sale';
    case Cancel = 'cancel';
    case Entry = 'entry';
    case Loss = 'loss';
    case Count = 'count';

    public function label(): string
    {
        return match ($this) {
            self::Sale => 'Venda',
            self::Cancel => 'Cancelamento (devolvido)',
            self::Entry => 'Entrada',
            self::Loss => 'Perda',
            self::Count => 'Contagem',
        };
    }
}
