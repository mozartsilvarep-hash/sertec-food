<?php

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Enums\OptionGroupType;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\MenuProduct;
use App\Modules\Catalog\Models\Option;
use App\Modules\Catalog\Models\OptionGroup;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductOptionGroup;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Tenancy\Support\PublicIds;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cria/atualiza produto com variantes, grupos de opções e categorias numa
 * transação. Toda referência vinda do cliente é resolvida no tenant atual.
 */
final class ProductWriter
{
    /**
     * @param  array{name?: string, description?: string|null, active?: bool, kind?: string, available_days?: list<int>|null, available_from?: string|null, available_until?: string|null, combo_slots?: list<array{id?: string|null, name: string, quantity: int, product_ids: list<string>}>, variants?: list<array{id?: string|null, name: string, price: string, active?: bool}>, option_group_ids?: list<string>, category_ids?: list<string>}  $data
     */
    public function save(?Product $product, array $data): Product
    {
        return DB::transaction(function () use ($product, $data) {
            $product ??= new Product;
            $product->fill(array_intersect_key($data, array_flip(['name', 'description', 'active', 'kind', 'available_days', 'available_from', 'available_until'])));
            if (array_key_exists('available_days', $data)) {
                $days = $data['available_days'] ?? [];
                // Todos os dias (ou nenhum marcado) = sem restrição.
                $product->available_days = $days === [] || count($days) === 7 ? null : array_map('intval', $days);
            }
            $product->kind ??= Product::KIND_ITEM;
            $product->save();

            if ($product->isCombo() && array_key_exists('combo_slots', $data)) {
                $this->syncComboSlots($product, $data['combo_slots']);
            }

            if (array_key_exists('variants', $data)) {
                $this->syncVariants($product, $data['variants']);
            }
            if (array_key_exists('option_group_ids', $data) || array_key_exists('combo_slots', $data)) {
                $this->syncOptionGroups($product, $data['option_group_ids'] ?? []);
            }
            if (array_key_exists('category_ids', $data)) {
                $this->syncCategories($product, $data['category_ids']);
            }

            return $product->refresh()->load(['variants', 'optionGroups.options.product', 'placements.category']);
        });
    }

    /**
     * @param  list<array{id?: string|null, name: string, price: string, active?: bool}>  $variants
     */
    private function syncVariants(Product $product, array $variants): void
    {
        $existing = $product->variants()->get()->keyBy('public_id');
        $keep = [];

        foreach ($variants as $position => $data) {
            $id = $data['id'] ?? null;

            if ($id !== null) {
                $variant = $existing->get($id);
                if ($variant === null) {
                    throw ValidationException::withMessages(["variants.{$position}.id" => 'Variante não pertence a este produto.']);
                }
            } else {
                $variant = new ProductVariant(['product_id' => $product->id]);
            }

            $variant->fill([
                'name' => $data['name'],
                'price' => $data['price'],
                'active' => $data['active'] ?? true,
                'sort_order' => $position,
            ])->save();

            $keep[] = $variant->id;
        }

        $product->variants()->whereNotIn('id', $keep)->delete();
    }

    /**
     * Cada espaço do combo vira um grupo "dono" do combo (fora da lista geral
     * de opções), com uma opção por produto escolhido. O preço é o do combo;
     * as opções não somam nada.
     *
     * @param  list<array{id?: string|null, name: string, quantity: int, product_ids: list<string>}>  $slots
     */
    private function syncComboSlots(Product $product, array $slots): void
    {
        $existing = OptionGroup::query()->where('owner_product_id', $product->id)->get()->keyBy('public_id');
        $keep = [];

        foreach ($slots as $position => $slot) {
            $id = $slot['id'] ?? null;
            $group = $id === null ? null : $existing->get($id);
            if ($id !== null && $group === null) {
                throw ValidationException::withMessages(["combo_slots.{$position}.id" => 'Item não pertence a este combo.']);
            }
            $group ??= new OptionGroup;
            $group->owner_product_id = $product->id;
            $group->fill([
                'name' => $slot['name'],
                'type' => OptionGroupType::Addon,
                'min_select' => $slot['quantity'],
                'max_select' => $slot['quantity'],
                'free_quantity' => 0,
                'pricing_rule' => null,
                'active' => true,
            ])->save();

            $products = PublicIds::resolve(Product::class, $slot['product_ids'], "combo_slots.{$position}.product_ids");
            if ($products->contains(fn (Product $p) => $p->id === $product->id || $p->isCombo())) {
                throw ValidationException::withMessages(["combo_slots.{$position}.product_ids" => 'Um combo não pode conter a si mesmo nem outro combo.']);
            }
            $options = $group->options()->get()->keyBy('product_id');
            $keepOptions = [];
            foreach ($products->values() as $order => $item) {
                $option = $options->get($item->id) ?? new Option(['option_group_id' => $group->id, 'product_id' => $item->id]);
                $option->fill([
                    'name' => $item->name,
                    'price_delta' => '0.00',
                    'max_quantity' => $slot['quantity'],
                    'sort_order' => $order,
                    'active' => true,
                ])->save();
                $keepOptions[] = $option->id;
            }
            $group->options()->whereNotIn('id', $keepOptions)->delete();
            $keep[] = $group->id;
        }

        OptionGroup::query()->where('owner_product_id', $product->id)->whereNotIn('id', $keep)->delete();
    }

    /**
     * Grupos avulsos escolhidos + (combo) os grupos do próprio combo, primeiro.
     *
     * @param  list<string>  $ids
     */
    private function syncOptionGroups(Product $product, array $ids): void
    {
        $groups = PublicIds::resolve(OptionGroup::class, $ids, 'option_group_ids')
            ->filter(fn (OptionGroup $g) => $g->owner_product_id === null);
        if ($product->isCombo()) {
            $owned = OptionGroup::query()->where('owner_product_id', $product->id)->orderBy('id')->get();
            $groups = $owned->concat($groups);
        }

        ProductOptionGroup::query()->where('product_id', $product->id)->delete();
        foreach ($groups->values() as $position => $group) {
            ProductOptionGroup::query()->create([
                'product_id' => $product->id,
                'option_group_id' => $group->id,
                'sort_order' => $position,
            ]);
        }
    }

    /**
     * @param  list<string>  $ids
     */
    private function syncCategories(Product $product, array $ids): void
    {
        $categories = PublicIds::resolve(Category::class, $ids, 'category_ids');
        $current = MenuProduct::query()->where('product_id', $product->id)->get()->keyBy('category_id');

        foreach ($categories as $category) {
            if (! $current->has($category->id)) {
                $last = MenuProduct::query()->where('category_id', $category->id)->max('sort_order');
                MenuProduct::query()->create([
                    'category_id' => $category->id,
                    'product_id' => $product->id,
                    'sort_order' => $last === null ? 0 : ((int) $last) + 1,
                ]);
            }
        }

        $keepIds = $categories->pluck('id')->all();
        MenuProduct::query()->where('product_id', $product->id)->whereNotIn('category_id', $keepIds)->delete();
    }
}
