<?php

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Enums\FractionPricingRule;
use App\Modules\Catalog\Enums\OptionGroupType;
use App\Modules\Catalog\Images\ProductImageStore;
use App\Modules\Catalog\Models\BranchProductAvailability;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Menu;
use App\Modules\Catalog\Models\MenuProduct;
use App\Modules\Catalog\Models\Option;
use App\Modules\Catalog\Models\OptionGroup;
use App\Modules\Catalog\Models\OptionVariantPrice;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Catalog\Models\Promotion;
use App\Modules\Tenancy\Models\Branch;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;

/**
 * Leitura pública do cardápio de uma unidade (somente itens ativos).
 * Preços exibidos são informativos; o valor cobrado vem do PriceCalculator.
 */
final class CatalogReader
{
    private const PRODUCT_RELATIONS = [
        'variants' => null,
        'optionGroups.options.variantPrices' => null,
        'optionGroups.options.product.variants' => null,
    ];

    /**
     * @return list<array<string, mixed>>
     */
    public function menus(Branch $branch): array
    {
        $soldOut = $this->soldOut($branch);
        $promotions = self::runningPromotions();

        $menus = Menu::query()
            ->where('active', true)
            ->where(fn (Builder $q) => $q->whereNull('branch_id')->orWhere('branch_id', $branch->id))
            ->with([
                'categories' => fn ($q) => $q->where('active', true),
                'categories.placements.product' => fn ($q) => $q->where('active', true),
                'categories.placements.product.variants',
                'categories.placements.product.optionGroups.options.variantPrices',
                'categories.placements.product.optionGroups.options.product.variants',
            ])
            ->orderBy('sort_order')->orderBy('id')
            ->get();

        return $menus->map(fn (Menu $menu) => [
            'id' => $menu->public_id,
            'name' => $menu->name,
            'categories' => $menu->categories
                ->map(fn (Category $category) => [
                    'id' => $category->public_id,
                    'name' => $category->name,
                    'description' => $category->description,
                    'products' => $category->placements
                        ->filter(fn (MenuProduct $p) => $p->product !== null && $this->activeVariants($p->product) !== [])
                        ->map(fn (MenuProduct $p) => $this->summary($p->product, $soldOut, $promotions))
                        ->values()->all(),
                ])
                ->filter(fn (array $c) => $c['products'] !== [])
                ->values()->all(),
        ])->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function product(Product $product, Branch $branch): array
    {
        $product->loadMissing(array_keys(self::PRODUCT_RELATIONS));
        $variants = $this->activeVariants($product);

        $soldOut = $this->soldOut($branch);

        return [
            ...$this->summary($product, $soldOut, self::runningPromotions([$product->id])),
            'variants' => array_map(fn (ProductVariant $v) => [
                'id' => $v->public_id,
                'name' => $v->name,
                'price' => $v->price,
            ], $variants),
            'option_groups' => $product->optionGroups
                ->filter(fn (OptionGroup $g) => $g->active)
                ->map(fn (OptionGroup $g) => [
                    'id' => $g->public_id,
                    'name' => $g->name,
                    'type' => $g->type->value,
                    'min_select' => $g->min_select,
                    'max_select' => $g->max_select,
                    'required' => $g->isRequired(),
                    'free_quantity' => $g->free_quantity,
                    'pricing_rule' => $g->pricing_rule?->value,
                    'options' => $g->options
                        ->filter(fn (Option $o) => $o->active)
                        ->map(fn (Option $o) => [
                            'id' => $o->public_id,
                            // Item de combo: segue o produto (esgotado, fora do dia, inativo).
                            'available' => $o->product_id === null || $this->linkedAvailable($o, $soldOut),
                            'name' => $o->name,
                            'description' => $o->description,
                            'price_delta' => $o->price_delta,
                            'max_quantity' => $o->max_quantity,
                            // Sabores: preço por tamanho (ULID da variante => preço).
                            'prices' => $g->type === OptionGroupType::Fraction
                                ? (object) $o->variantPrices
                                    ->mapWithKeys(fn (OptionVariantPrice $p) => [$this->variantPublicId($variants, $p->product_variant_id) => $p->price])
                                    ->filter(fn ($price, $key) => $key !== '')
                                    ->all()
                                : null,
                        ])->values()->all(),
                ])->values()->all(),
        ];
    }

    /**
     * Promoções vigentes por product_id (a de maior desconto, se houver mais de uma).
     *
     * @param  list<int>|null  $productIds
     * @return array<int, Promotion>
     */
    public static function runningPromotions(?array $productIds = null): array
    {
        $map = [];
        Promotion::query()->running()
            ->when($productIds !== null, fn ($q) => $q->whereIn('product_id', $productIds ?? []))
            ->orderBy('percent_bp')->get()
            ->each(function (Promotion $p) use (&$map) {
                $map[$p->product_id] = $p;
            });

        return $map;
    }

    /**
     * @param  array<int, true>  $soldOut
     * @param  array<int, Promotion>  $promotions
     * @return array<string, mixed>
     */
    private function summary(Product $product, array $soldOut, array $promotions = []): array
    {
        $variants = $this->activeVariants($product);
        $priceFrom = $this->priceFrom($product, $variants);
        $promotion = $promotions[$product->id] ?? null;
        $percent = $promotion !== null && ! $promotion->isBundle();
        $schedule = $product->schedule();

        return [
            'id' => $product->public_id,
            'name' => $product->name,
            'description' => $product->description,
            'image_url' => ProductImageStore::url($product->image_key),
            'thumb_url' => ProductImageStore::thumbUrl($product->image_key),
            'kind' => $product->kind,
            'available' => ! isset($soldOut[$product->id]) && $schedule->isAvailableAt(),
            // "Só sáb. e dom.", "Das 11h às 15h" (null = sempre).
            'availability' => $schedule->label(),
            // Combo: soma dos itens avulsos (o mais barato de cada espaço), para "economize".
            'compare_at' => $product->isCombo() ? $this->comboCompareAt($product) : null,
            'has_options' => count($variants) > 1 || $product->optionGroups->contains(fn (OptionGroup $g) => $g->active),
            'price_from' => $priceFrom,
            // Preço exibido já com a promoção; o servidor recalcula no carrinho.
            'promo_price_from' => ! $percent || $priceFrom === null ? null : Money::format(Promotion::apply(Money::toCents($priceFrom), $promotion->percent_bp)),
            'promotion' => $promotion === null ? null : [
                'id' => $promotion->public_id,
                'type' => $promotion->type,
                'percent_bp' => $promotion->percent_bp,
                'buy_quantity' => $promotion->buy_quantity,
                'pay_quantity' => $promotion->pay_quantity,
                'label' => $promotion->label(),
                'featured' => $promotion->featured,
                'ends_at' => $promotion->ends_at?->toIso8601String(),
            ],
        ];
    }

    /**
     * Menor preço possível respeitando os grupos obrigatórios.
     *
     * @param  list<ProductVariant>  $variants
     */
    private function priceFrom(Product $product, array $variants): ?string
    {
        $best = null;
        $groups = $product->optionGroups->filter(fn (OptionGroup $g) => $g->active && $g->min_select > 0);

        foreach ($variants as $variant) {
            $cents = Money::toCents($variant->price);

            foreach ($groups as $group) {
                $options = $group->options->filter(fn (Option $o) => $o->active);

                if ($group->type === OptionGroupType::Fraction) {
                    $prices = $options
                        ->map(fn (Option $o) => $o->variantPrices->first(fn ($p) => $p->product_variant_id === $variant->id))
                        ->filter()
                        ->map(fn (OptionVariantPrice $p) => Money::toCents($p->price))
                        ->sort()->values()->all();

                    if (count($prices) < $group->min_select) {
                        continue 2; // tamanho sem sabores suficientes: ignora
                    }
                    $cheapest = array_slice($prices, 0, $group->min_select);
                    $cents += ($group->pricing_rule === FractionPricingRule::Average) ? Money::averageCents($cheapest) : max($cheapest);
                } else {
                    $units = [];
                    foreach ($options as $o) {
                        array_push($units, ...array_fill(0, $o->max_quantity, Money::toCents($o->price_delta)));
                    }
                    sort($units);
                    $cents += array_sum(array_slice($units, $group->free_quantity, max(0, $group->min_select - $group->free_quantity)));
                }
            }

            $best = $best === null ? $cents : min($best, $cents);
        }

        return $best === null ? null : Money::format($best);
    }

    /**
     * @param  array<int, true>  $soldOut
     */
    private function linkedAvailable(Option $option, array $soldOut): bool
    {
        $linked = $option->product;

        return $linked !== null && $linked->active && ! $linked->trashed()
            && ! isset($soldOut[$linked->id]) && $linked->schedule()->isAvailableAt();
    }

    private function comboCompareAt(Product $product): ?string
    {
        $total = 0;
        foreach ($product->optionGroups->filter(fn (OptionGroup $g) => $g->active && $g->min_select > 0) as $group) {
            $cheapest = $group->options
                ->filter(fn (Option $o) => $o->active && $o->product !== null)
                ->map(fn (Option $o) => $o->product?->variants->where('active', true)->map(fn (ProductVariant $v) => Money::toCents($v->price))->min())
                ->filter(fn ($c) => $c !== null)
                ->min();
            if ($cheapest === null) {
                return null;
            }
            $total += (int) $cheapest * $group->min_select;
        }

        return $total > 0 ? Money::format($total) : null;
    }

    /**
     * @return list<ProductVariant>
     */
    private function activeVariants(Product $product): array
    {
        return $product->variants->filter(fn (ProductVariant $v) => $v->active)->values()->all();
    }

    /**
     * @param  list<ProductVariant>  $variants
     */
    private function variantPublicId(array $variants, int $id): string
    {
        foreach ($variants as $variant) {
            if ($variant->id === $id) {
                return $variant->public_id;
            }
        }

        return '';
    }

    /**
     * @return array<int, true>
     */
    private function soldOut(Branch $branch): array
    {
        $ids = [];
        $rows = BranchProductAvailability::query()
            ->where('branch_id', $branch->id)
            ->where('available', false)
            ->pluck('product_id');

        foreach ($rows as $id) {
            $ids[(int) $id] = true;
        }

        return $ids;
    }
}
