<?php

namespace App\Modules\Catalog\Pricing;

use App\Support\Money;

/**
 * Resultado do cálculo de preço de UMA unidade de produto configurado.
 * Valores em centavos internamente; toArray() devolve strings "12.34".
 */
final class PriceQuote
{
    /**
     * @param  list<array{group:string, group_name:string, option:string, option_name:string, quantity:int, charged_quantity:int, unit_price_cents:int, total_cents:int}>  $lines
     */
    public function __construct(
        public readonly string $productId,
        public readonly string $productName,
        public readonly string $variantId,
        public readonly string $variantName,
        public readonly int $basePriceCents,
        public readonly int $fractionPriceCents,
        public readonly array $lines,
        public readonly int $unitPriceCents,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'product' => ['id' => $this->productId, 'name' => $this->productName],
            'variant' => ['id' => $this->variantId, 'name' => $this->variantName],
            'base_price' => Money::format($this->basePriceCents),
            'fraction_price' => Money::format($this->fractionPriceCents),
            'options' => array_map(fn (array $l) => [
                'group' => $l['group'],
                'group_name' => $l['group_name'],
                'option' => $l['option'],
                'option_name' => $l['option_name'],
                'quantity' => $l['quantity'],
                'charged_quantity' => $l['charged_quantity'],
                'unit_price' => Money::format($l['unit_price_cents']),
                'total' => Money::format($l['total_cents']),
            ], $this->lines),
            'unit_price' => Money::format($this->unitPriceCents),
        ];
    }
}
