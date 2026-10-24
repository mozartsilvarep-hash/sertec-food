<?php

namespace App\Modules\Catalog\Enums;

enum FractionPricingRule: string
{
    /** Cobra o preço do sabor mais caro (padrão de mercado). */
    case Highest = 'highest';

    /** Cobra a média dos preços dos sabores escolhidos. */
    case Average = 'average';
}
