<?php

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\MenuProduct;
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
     * @param  array{name?: string, description?: string|null, active?: bool, variants?: list<array{id?: string|null, name: string, price: string, active?: bool}>, option_group_ids?: list<string>, category_ids?: list<string>}  $data
     */
    public function save(?Product $product, array $data): Product
    {
        return DB::transaction(function () use ($product, $data) {
            $product ??= new Product;
            $product->fill(array_intersect_key($data, array_flip(['name', 'description', 'active'])));
            $product->save();

            if (array_key_exists('variants', $data)) {
                $this->syncVariants($product, $data['variants']);
            }
            if (array_key_exists('option_group_ids', $data)) {
                $this->syncOptionGroups($product, $data['option_group_ids']);
            }
            if (array_key_exists('category_ids', $data)) {
                $this->syncCategories($product, $data['category_ids']);
            }

            return $product->refresh()->load(['variants', 'optionGroups', 'placements.category']);
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
     * @param  list<string>  $ids
     */
    private function syncOptionGroups(Product $product, array $ids): void
    {
        $groups = PublicIds::resolve(OptionGroup::class, $ids, 'option_group_ids');

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
