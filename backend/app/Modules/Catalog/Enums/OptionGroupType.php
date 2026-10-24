<?php

namespace App\Modules\Catalog\Enums;

enum OptionGroupType: string
{
    /** Adicionais: cada opção soma price_delta × quantidade. */
    case Addon = 'addon';

    /** Sabores fracionados (pizza meio a meio): preço pela regra do grupo. */
    case Fraction = 'fraction';
}
