<?php

namespace App\Modules\Catalog\Pricing;

use App\Modules\Catalog\Enums\FractionPricingRule;
use App\Modules\Catalog\Enums\OptionGroupType;
use App\Modules\Catalog\Models\BranchProductAvailability;
use App\Modules\Catalog\Models\Option;
use App\Modules\Catalog\Models\OptionGroup;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Catalog\Models\Promotion;
use App\Modules\Tenancy\Models\Branch;
use App\Support\Money;

/**
 * Fonte única do preço de um produto configurado. O preço enviado pelo
 * navegador NUNCA é usado (PRODUCT.md). O carrinho/checkout (Sprint 2)
 * chama este serviço para cada item.
 *
 * Preço unitário = preço da variante
 *                + preço dos sabores (grupo "fraction": maior ou média)
 *                + adicionais (grupo "addon": price_delta × qtd, menos as N
 *                  unidades grátis — sempre as mais baratas)
 *                − promoção vigente do produto (percentual, arredondado ao centavo).
 */
final class PriceCalculator
{
    /**
     * @param  list<array<string, mixed>>  $selections  [{option: ULID, quantity?: int}] vindos do cliente
     */
    public function quote(Product $product, string $variantId, array $selections, ?Branch $branch = null): PriceQuote
    {
        if (! $product->active || $product->trashed()) {
            throw new PricingException('product_unavailable', 'Este produto não está disponível.');
        }

        if ($branch !== null && $this->isSoldOut($product, $branch)) {
            throw new PricingException('product_sold_out', 'Este produto está esgotado nesta unidade.');
        }

        $schedule = $product->schedule();
        if (! $schedule->isAvailableAt()) {
            throw new PricingException('product_not_available_now', rtrim("\"{$product->name}\" está disponível {$this->lower($schedule->label())}", '.').'.');
        }

        $product->loadMissing(['variants', 'optionGroups.options.variantPrices', 'optionGroups.options.product']);

        /** @var ProductVariant|null $variant */
        $variant = $product->variants->first(fn (ProductVariant $v) => $v->public_id === $variantId && $v->active);
        if ($variant === null) {
            throw new PricingException('variant_invalid', 'Escolha um tamanho válido.');
        }

        $groups = $product->optionGroups->filter(fn (OptionGroup $g) => $g->active)->values();
        $chosen = $this->resolveSelections($groups->all(), $selections);
        $this->assertLinkedProductsAvailable($chosen, $branch);

        $fractionCents = 0;
        $lines = [];

        foreach ($groups as $group) {
            /** @var array<string, array{option: Option, quantity: int}> $picks */
            $picks = $chosen[$group->id] ?? [];
            $total = array_sum(array_column($picks, 'quantity'));

            if ($total < $group->min_select) {
                throw new PricingException('group_min_not_met', "Escolha pelo menos {$group->min_select} em \"{$group->name}\".", ['group' => $group->public_id]);
            }
            if ($total > $group->max_select) {
                throw new PricingException('group_max_exceeded', "Escolha no máximo {$group->max_select} em \"{$group->name}\".", ['group' => $group->public_id]);
            }

            if ($group->type === OptionGroupType::Fraction) {
                [$cents, $groupLines] = $this->priceFraction($group, $variant, $picks);
                $fractionCents += $cents;
            } else {
                $groupLines = $this->priceAddons($group, $picks);
            }

            array_push($lines, ...$groupLines);
        }

        $addonCents = array_sum(array_column($lines, 'total_cents'));
        $baseCents = Money::toCents($variant->price);
        $fullCents = $baseCents + $fractionCents + $addonCents;

        // Promoção vigente: desconto percentual sobre a unidade inteira.
        // ("leve N, pague M" é calculado no carrinho, sobre a quantidade total.)
        $promotion = Promotion::query()->running()->where('product_id', $product->id)
            ->where('type', Promotion::TYPE_PERCENT)->orderByDesc('percent_bp')->first();
        $unitCents = $promotion === null ? $fullCents : Promotion::apply($fullCents, $promotion->percent_bp);

        return new PriceQuote(
            productId: $product->public_id,
            productName: $product->name,
            variantId: $variant->public_id,
            variantName: $variant->name,
            basePriceCents: $baseCents,
            fractionPriceCents: $fractionCents,
            lines: $lines,
            unitPriceCents: $unitCents,
            originalUnitPriceCents: $fullCents,
            promotionId: $promotion?->public_id,
            promotionLabel: $promotion?->label(),
        );
    }

    /**
     * Itens de combo: cada opção aponta para um produto do cardápio, que
     * precisa estar ativo, com estoque na unidade e disponível agora.
     *
     * @param  array<int, array<string, array{option: Option, quantity: int}>>  $chosen
     */
    private function assertLinkedProductsAvailable(array $chosen, ?Branch $branch): void
    {
        foreach ($chosen as $picks) {
            foreach ($picks as $pick) {
                $linked = $pick['option']->product;
                if ($pick['option']->product_id === null) {
                    continue;
                }
                $ok = $linked !== null && $linked->active && ! $linked->trashed()
                    && ($branch === null || ! $this->isSoldOut($linked, $branch))
                    && $linked->schedule()->isAvailableAt();
                if (! $ok) {
                    throw new PricingException('option_invalid', "\"{$pick['option']->name}\" não está disponível agora. Escolha outro.", ['option' => $pick['option']->public_id]);
                }
            }
        }
    }

    private function lower(?string $text): string
    {
        return $text === null ? 'em outros horários' : mb_strtolower(mb_substr($text, 0, 1)).mb_substr($text, 1);
    }

    private function isSoldOut(Product $product, Branch $branch): bool
    {
        return BranchProductAvailability::query()
            ->where('branch_id', $branch->id)
            ->where('product_id', $product->id)
            ->where('available', false)
            ->exists();
    }

    /**
     * @param  list<OptionGroup>  $groups
     * @param  list<array<string, mixed>>  $selections
     * @return array<int, array<string, array{option: Option, quantity: int}>>
     */
    private function resolveSelections(array $groups, array $selections): array
    {
        $index = [];
        foreach ($groups as $group) {
            foreach ($group->options as $option) {
                $index[$option->public_id] = $option;
            }
        }

        $chosen = [];
        foreach ($selections as $selection) {
            $optionId = is_string($selection['option'] ?? null) ? $selection['option'] : '';
            $quantity = is_int($selection['quantity'] ?? null) ? $selection['quantity'] : (isset($selection['quantity']) ? 0 : 1);
            $option = $index[$optionId] ?? null;

            // Opção de outro produto, de outro tenant ou inexistente: mesma resposta.
            if ($option === null || ! $option->active) {
                throw new PricingException('option_invalid', 'Uma das opções escolhidas não está disponível.', ['option' => $optionId]);
            }
            if ($quantity < 1 || $quantity > $option->max_quantity) {
                throw new PricingException('option_quantity_invalid', "Quantidade inválida para \"{$option->name}\".", ['option' => $optionId]);
            }
            if (isset($chosen[$option->option_group_id][$optionId])) {
                throw new PricingException('option_duplicated', "\"{$option->name}\" foi informado mais de uma vez.", ['option' => $optionId]);
            }

            $chosen[$option->option_group_id][$optionId] = ['option' => $option, 'quantity' => $quantity];
        }

        return $chosen;
    }

    /**
     * @param  array<string, array{option: Option, quantity: int}>  $picks
     * @return array{0: int, 1: list<array{group:string, group_name:string, option:string, option_name:string, quantity:int, charged_quantity:int, unit_price_cents:int, total_cents:int}>}
     */
    private function priceFraction(OptionGroup $group, ProductVariant $variant, array $picks): array
    {
        $prices = [];
        $lines = [];

        foreach ($picks as $pick) {
            $option = $pick['option'];
            if ($pick['quantity'] !== 1) {
                throw new PricingException('option_quantity_invalid', "Cada sabor conta uma vez em \"{$group->name}\".", ['option' => $option->public_id]);
            }

            $price = $option->variantPrices->first(fn ($p) => $p->product_variant_id === $variant->id);
            if ($price === null) {
                throw new PricingException('flavor_unavailable_for_size', "O sabor \"{$option->name}\" não está disponível no tamanho {$variant->name}.", ['option' => $option->public_id]);
            }

            $cents = Money::toCents($price->price);
            $prices[] = $cents;
            $lines[] = $this->line($group, $option, 1, 0, $cents, 0);
        }

        if ($prices === []) {
            return [0, $lines];
        }

        $rule = $group->pricing_rule ?? FractionPricingRule::Highest;
        $charged = $rule === FractionPricingRule::Average ? Money::averageCents($prices) : max($prices);

        return [$charged, $lines];
    }

    /**
     * @param  array<string, array{option: Option, quantity: int}>  $picks
     * @return list<array{group:string, group_name:string, option:string, option_name:string, quantity:int, charged_quantity:int, unit_price_cents:int, total_cents:int}>
     */
    private function priceAddons(OptionGroup $group, array $picks): array
    {
        // Distribui as unidades grátis começando pelas opções mais baratas.
        $ordered = array_values($picks);
        usort($ordered, fn ($a, $b) => Money::toCents($a['option']->price_delta) <=> Money::toCents($b['option']->price_delta));

        $freeLeft = $group->free_quantity;
        $lines = [];

        foreach ($ordered as $pick) {
            $unit = Money::toCents($pick['option']->price_delta);
            $free = min($freeLeft, $pick['quantity']);
            $freeLeft -= $free;
            $charged = $pick['quantity'] - $free;
            $lines[] = $this->line($group, $pick['option'], $pick['quantity'], $charged, $unit, $unit * $charged);
        }

        return $lines;
    }

    /**
     * @return array{group:string, group_name:string, option:string, option_name:string, quantity:int, charged_quantity:int, unit_price_cents:int, total_cents:int}
     */
    private function line(OptionGroup $group, Option $option, int $quantity, int $charged, int $unit, int $total): array
    {
        return [
            'group' => $group->public_id,
            'group_name' => $group->name,
            'option' => $option->public_id,
            'option_name' => $option->name,
            'quantity' => $quantity,
            'charged_quantity' => $charged,
            'unit_price_cents' => $unit,
            'total_cents' => $total,
        ];
    }
}
