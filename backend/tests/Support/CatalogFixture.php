<?php

namespace Tests\Support;

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Menu;
use App\Modules\Catalog\Models\MenuProduct;
use App\Modules\Catalog\Models\Option;
use App\Modules\Catalog\Models\OptionGroup;
use App\Modules\Catalog\Models\OptionVariantPrice;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductOptionGroup;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Tenancy\Models\Branch;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\TenantContext;

/**
 * Cardápio de pizzaria para testes, criado dentro do contexto do tenant:
 * - Pizza (Média R$ 0, Grande R$ 5 de massa) com grupo de sabores (fraction)
 *   e grupo de bordas (addon, 1 grátis);
 * - Refrigerante (preço único).
 */
final class CatalogFixture
{
    public Tenant $tenant;

    public Branch $branch;

    public Menu $menu;

    public Category $pizzas;

    public Category $drinks;

    public Product $pizza;

    public ProductVariant $medium;

    public ProductVariant $large;

    public OptionGroup $flavors;

    /** @var array<string, Option> */
    public array $flavor = [];

    public OptionGroup $crusts;

    /** @var array<string, Option> */
    public array $crust = [];

    public Product $soda;

    public ProductVariant $sodaVariant;

    public static function create(Tenant $tenant, ?Branch $branch = null): self
    {
        $f = new self;
        $f->tenant = $tenant;

        app(TenantContext::class)->run($tenant, function () use ($f, $branch) {
            $f->branch = $branch ?? Branch::query()->create(['name' => 'Centro']);
            $f->menu = Menu::query()->create(['name' => 'Cardápio']);
            $f->pizzas = Category::query()->create(['menu_id' => $f->menu->id, 'name' => 'Pizzas', 'sort_order' => 0]);
            $f->drinks = Category::query()->create(['menu_id' => $f->menu->id, 'name' => 'Bebidas', 'sort_order' => 1]);

            $f->pizza = Product::query()->create(['name' => 'Pizza']);
            $f->medium = ProductVariant::query()->create(['product_id' => $f->pizza->id, 'name' => 'Média', 'price' => '0.00', 'sort_order' => 0]);
            $f->large = ProductVariant::query()->create(['product_id' => $f->pizza->id, 'name' => 'Grande', 'price' => '5.00', 'sort_order' => 1]);

            $f->flavors = OptionGroup::query()->create([
                'name' => 'Sabores', 'type' => 'fraction', 'min_select' => 1, 'max_select' => 2, 'pricing_rule' => 'highest',
            ]);
            // Calabresa e Portuguesa nos dois tamanhos; Camarão só na Grande.
            foreach (['Calabresa' => ['40.00', '50.00'], 'Portuguesa' => ['45.01', '55.00'], 'Camarão' => [null, '80.00']] as $name => [$m, $g]) {
                $option = Option::query()->create(['option_group_id' => $f->flavors->id, 'name' => $name]);
                if ($m !== null) {
                    OptionVariantPrice::query()->create(['option_id' => $option->id, 'product_variant_id' => $f->medium->id, 'price' => $m]);
                }
                OptionVariantPrice::query()->create(['option_id' => $option->id, 'product_variant_id' => $f->large->id, 'price' => $g]);
                $f->flavor[$name] = $option;
            }

            $f->crusts = OptionGroup::query()->create(['name' => 'Bordas e extras', 'type' => 'addon', 'min_select' => 0, 'max_select' => 4, 'free_quantity' => 1]);
            foreach (['Catupiry' => ['8.00', 2], 'Cheddar' => ['6.00', 2], 'Bacon' => ['4.50', 3]] as $name => [$delta, $maxQty]) {
                $f->crust[$name] = Option::query()->create([
                    'option_group_id' => $f->crusts->id, 'name' => $name, 'price_delta' => $delta, 'max_quantity' => $maxQty,
                ]);
            }

            ProductOptionGroup::query()->create(['product_id' => $f->pizza->id, 'option_group_id' => $f->flavors->id, 'sort_order' => 0]);
            ProductOptionGroup::query()->create(['product_id' => $f->pizza->id, 'option_group_id' => $f->crusts->id, 'sort_order' => 1]);
            MenuProduct::query()->create(['category_id' => $f->pizzas->id, 'product_id' => $f->pizza->id]);

            $f->soda = Product::query()->create(['name' => 'Refrigerante lata']);
            $f->sodaVariant = ProductVariant::query()->create(['product_id' => $f->soda->id, 'name' => 'Única', 'price' => '6.50']);
            MenuProduct::query()->create(['category_id' => $f->drinks->id, 'product_id' => $f->soda->id]);
        });

        return $f;
    }

    /**
     * @param  array<string, int>  $flavors  nome => 1
     * @param  array<string, int>  $crusts  nome => quantidade
     * @return list<array{option: string, quantity: int}>
     */
    public function pick(array $flavors = [], array $crusts = []): array
    {
        $selections = [];
        foreach ($flavors as $name => $qty) {
            $selections[] = ['option' => $this->flavor[$name]->public_id, 'quantity' => $qty];
        }
        foreach ($crusts as $name => $qty) {
            $selections[] = ['option' => $this->crust[$name]->public_id, 'quantity' => $qty];
        }

        return $selections;
    }
}
