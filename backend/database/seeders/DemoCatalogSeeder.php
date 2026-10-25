<?php

namespace Database\Seeders;

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Menu;
use App\Modules\Catalog\Models\MenuProduct;
use App\Modules\Catalog\Models\Option;
use App\Modules\Catalog\Models\OptionGroup;
use App\Modules\Catalog\Models\OptionVariantPrice;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductOptionGroup;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Ordering\Models\DeliveryZone;
use App\Modules\Tenancy\Models\Branch;
use App\Modules\Tenancy\Models\BranchOpeningHour;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Database\Seeder;

/**
 * Cardápios de demonstração (somente local/testing, chamado pelo DemoSeeder).
 */
class DemoCatalogSeeder extends Seeder
{
    public function run(TenantContext $context): void
    {
        $pizzaria = Tenant::query()->where('slug', 'pizzaria-demo')->first();
        $burger = Tenant::query()->where('slug', 'burger-demo')->first();

        if ($pizzaria !== null) {
            $context->run($pizzaria, fn () => $this->pizzaria($pizzaria));
        }
        if ($burger !== null) {
            $context->run($burger, fn () => $this->burger($burger));
        }
    }

    private function pizzaria(Tenant $tenant): void
    {
        $tenant->update(['brand_color' => '#9a3412', 'description' => 'Pizzas no forno a lenha desde 1998. Meio a meio sem custo extra.']);
        $this->hours([2, 3, 4, 5, 6, 0], '18:00', '23:30');
        $this->zones([
            ['Orla', ['Pajuçara', 'Ponta Verde', 'Jatiúca'], '6.00', '30.00', '120.00', 40],
            ['Centro e Farol', ['Centro', 'Farol', 'Poço'], '9.00', '40.00', null, 55],
        ]);

        $menu = Menu::query()->create(['name' => 'Cardápio']);
        $pizzas = Category::query()->create(['menu_id' => $menu->id, 'name' => 'Pizzas', 'description' => 'Escolha até 2 sabores', 'sort_order' => 0]);
        $drinks = Category::query()->create(['menu_id' => $menu->id, 'name' => 'Bebidas', 'sort_order' => 1]);
        $desserts = Category::query()->create(['menu_id' => $menu->id, 'name' => 'Sobremesas', 'sort_order' => 2]);

        $pizza = $this->product($pizzas, 'Pizza tradicional', 'Massa de fermentação natural, 8 fatias na grande.', [['Média', '0.00'], ['Grande', '0.00']]);
        [$medium, $large] = $pizza->variants()->get()->all();

        $flavors = OptionGroup::query()->create(['name' => 'Sabores', 'type' => 'fraction', 'min_select' => 1, 'max_select' => 2, 'pricing_rule' => 'highest']);
        $flavorList = [
            'Calabresa' => ['39.90', '52.90'], 'Margherita' => ['42.90', '55.90'], 'Portuguesa' => ['44.90', '58.90'],
            'Frango com catupiry' => ['44.90', '58.90'], 'Quatro queijos' => ['47.90', '62.90'], 'Camarão' => [null, '79.90'],
        ];
        $i = 0;
        foreach ($flavorList as $name => [$m, $g]) {
            $option = Option::query()->create(['option_group_id' => $flavors->id, 'name' => $name, 'sort_order' => $i++]);
            if ($m !== null) {
                OptionVariantPrice::query()->create(['option_id' => $option->id, 'product_variant_id' => $medium->id, 'price' => $m]);
            }
            OptionVariantPrice::query()->create(['option_id' => $option->id, 'product_variant_id' => $large->id, 'price' => $g]);
        }

        $crust = $this->addons('Borda recheada', 0, 1, 0, ['Catupiry' => '9.00', 'Cheddar' => '9.00', 'Chocolate' => '11.00']);
        $extras = $this->addons('Adicionais', 0, 5, 0, ['Bacon' => '6.00', 'Azeitona' => '3.00', 'Ovo' => '3.50'], 3);
        foreach ([$flavors, $crust, $extras] as $pos => $group) {
            ProductOptionGroup::query()->create(['product_id' => $pizza->id, 'option_group_id' => $group->id, 'sort_order' => $pos]);
        }

        $this->product($drinks, 'Refrigerante lata', '350 ml', [['Única', '6.50']]);
        $this->product($drinks, 'Suco natural', 'Laranja, maracujá ou acerola', [['300 ml', '9.00'], ['500 ml', '13.00']]);
        $this->product($desserts, 'Petit gâteau', 'Com sorvete de creme', [['Única', '22.00']]);
    }

    private function burger(Tenant $tenant): void
    {
        $tenant->update(['brand_color' => '#1e3a8a', 'description' => 'Smash burgers e batatas crocantes.']);
        $this->hours([1, 2, 3, 4, 5, 6, 0], '11:00', '15:00');
        $this->hours([5, 6], '18:00', '02:00');
        $this->zones([['Bairros próximos', ['Jatiúca', 'Cruz das Almas', 'Mangabeiras'], '5.00', '25.00', '80.00', 35]]);

        $menu = Menu::query()->create(['name' => 'Cardápio']);
        $burgers = Category::query()->create(['menu_id' => $menu->id, 'name' => 'Burgers', 'sort_order' => 0]);
        $sides = Category::query()->create(['menu_id' => $menu->id, 'name' => 'Acompanhamentos', 'sort_order' => 1]);

        $extras = $this->addons('Turbine seu burger', 0, 6, 1, ['Bacon' => '5.00', 'Cheddar extra' => '4.00', 'Cebola caramelizada' => '3.50'], 3);
        foreach (['Smash clássico' => '29.90', 'Smash duplo' => '36.90', 'Chicken crispy' => '31.90'] as $name => $price) {
            $p = $this->product($burgers, $name, 'Pão brioche, molho da casa', [['Único', $price]]);
            ProductOptionGroup::query()->create(['product_id' => $p->id, 'option_group_id' => $extras->id]);
        }
        $this->product($sides, 'Batata frita', 'Porção individual', [['P', '12.00'], ['G', '19.00']]);
    }

    /**
     * @param  list<array{0:string,1:string}>  $variants
     */
    private function product(Category $category, string $name, string $description, array $variants): Product
    {
        $product = Product::query()->create(['name' => $name, 'description' => $description]);
        foreach ($variants as $pos => [$variantName, $price]) {
            ProductVariant::query()->create(['product_id' => $product->id, 'name' => $variantName, 'price' => $price, 'sort_order' => $pos]);
        }
        MenuProduct::query()->create([
            'category_id' => $category->id,
            'product_id' => $product->id,
            'sort_order' => MenuProduct::query()->where('category_id', $category->id)->count(),
        ]);

        return $product;
    }

    /**
     * @param  array<string, string>  $options
     */
    private function addons(string $name, int $min, int $max, int $free, array $options, int $maxQty = 1): OptionGroup
    {
        $group = OptionGroup::query()->create(['name' => $name, 'type' => 'addon', 'min_select' => $min, 'max_select' => $max, 'free_quantity' => $free]);
        $i = 0;
        foreach ($options as $optionName => $price) {
            Option::query()->create(['option_group_id' => $group->id, 'name' => $optionName, 'price_delta' => $price, 'max_quantity' => $maxQty, 'sort_order' => $i++]);
        }

        return $group;
    }

    /**
     * @param  list<array{0:string,1:list<string>,2:string,3:string|null,4:string|null,5:int}>  $zones
     */
    private function zones(array $zones): void
    {
        foreach (Branch::query()->get() as $branch) {
            $branch->update(['delivery_enabled' => true, 'pickup_enabled' => true]);
            foreach ($zones as $i => [$name, $neighborhoods, $fee, $min, $free, $eta]) {
                DeliveryZone::query()->create([
                    'branch_id' => $branch->id, 'name' => $name, 'neighborhoods_json' => $neighborhoods, 'fee' => $fee,
                    'minimum_order' => $min, 'free_above' => $free, 'eta_minutes' => $eta, 'sort_order' => $i,
                ]);
            }
        }
    }

    /**
     * @param  list<int>  $weekdays
     */
    private function hours(array $weekdays, string $opens, string $closes): void
    {
        foreach (Branch::query()->get() as $branch) {
            foreach ($weekdays as $weekday) {
                BranchOpeningHour::query()->create(['branch_id' => $branch->id, 'weekday' => $weekday, 'opens_at' => $opens, 'closes_at' => $closes]);
            }
        }
    }
}
