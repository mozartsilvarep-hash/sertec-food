<?php

use App\Modules\Catalog\Models\Promotion;
use App\Modules\Identity\Models\Role;
use App\Modules\Ordering\Models\Order;
use Tests\Support\CatalogFixture;
use Tests\Support\Shop;

beforeEach(function () {
    $this->a = makeTenant('tenant-a');
    $this->b = makeTenant('tenant-b');
    $this->fa = CatalogFixture::create($this->a);
    $this->fb = CatalogFixture::create($this->b);
    $this->session = merchantLogin($this->a, makeMember($this->a));
});

function promo(object $t, array $body, string $method = 'post', string $path = '')
{
    return withSession($t->session)->{$method.'Json'}(tenantUrl($t->a, 'merchant/promotions'.$path), $body);
}

it('preço promocional vira percentual sobre o "a partir de" e vale no cardápio, carrinho e pedido', function () {
    // Pizza média calabresa: R$ 40,00 → "por" R$ 34,90
    promo($this, ['product_id' => $this->fa->pizza->public_id, 'promo_price' => '34,90', 'featured' => true])->assertCreated()
        ->assertJsonPath('data.percent', '12.75')
        ->assertJsonPath('data.label', '-12,75%')
        ->assertJsonPath('data.state', 'running');

    $menus = $this->getJson(tenantUrl($this->a, 'menus'))->assertOk()->json('data.menus');
    $pizza = collect($menus)->flatMap(fn ($m) => $m['categories'])->flatMap(fn ($c) => $c['products'])->firstWhere('id', $this->fa->pizza->public_id);
    expect($pizza['price_from'])->toBe('40.00')
        ->and($pizza['promo_price_from'])->toBe('34.90')
        ->and($pizza['promotion']['featured'])->toBeTrue();

    $token = Shop::newCart($this->a);
    Shop::addPizza($this->fa, $token, 2, ['Calabresa' => 1])->assertCreated()
        ->assertJsonPath('data.items.0.unit_price', '34.90')
        ->assertJsonPath('data.items.0.original_unit_price', '40.00')
        ->assertJsonPath('data.subtotal', '69.80');

    $order = Shop::checkout($this->a, Shop::pickupPayload($token))->assertCreated()->assertJsonPath('data.total', '69.80')->json('data.order_id');
    expect(inTenant($this->a, fn () => Order::query()->where('public_id', $order)->with('items')->sole()->items[0]->unit_price))->toBe('34.90');
});

it('desconto vale sobre a unidade inteira (sabores e adicionais) e respeita o período', function () {
    promo($this, ['product_id' => $this->fa->pizza->public_id, 'percent' => '10'])->assertCreated();

    $token = Shop::newCart($this->a);
    // Calabresa + Portuguesa com 2x Bacon: 49,51 cheio → 44,56 (10% arredondado ao centavo)
    Shop::addPizza($this->fa, $token, 1, ['Calabresa' => 1, 'Portuguesa' => 1], ['Bacon' => 2])->assertCreated()
        ->assertJsonPath('data.items.0.original_unit_price', '49.51')
        ->assertJsonPath('data.items.0.unit_price', '44.56');

    // Promoção futura não vale ainda.
    inTenant($this->a, fn () => Promotion::query()->update(['starts_at' => now()->addDay()]));
    $this->getJson(tenantUrl($this->a, "carts/{$token}"))->assertJsonPath('data.items.0.unit_price', '49.51');
});

it('não permite duas promoções no mesmo período do mesmo produto', function () {
    promo($this, ['product_id' => $this->fa->soda->public_id, 'percent' => '10', 'starts_at' => '2026-12-01T00:00', 'ends_at' => '2026-12-10T00:00'])->assertCreated();
    promo($this, ['product_id' => $this->fa->soda->public_id, 'percent' => '20', 'starts_at' => '2026-12-05T00:00'])
        ->assertStatus(409)->assertJsonPath('code', 'promotion_overlap');
    promo($this, ['product_id' => $this->fa->soda->public_id, 'percent' => '20', 'starts_at' => '2026-12-10T00:00'])->assertCreated();
    promo($this, ['product_id' => $this->fa->soda->public_id, 'promo_price' => '9,00'])->assertStatus(422);
    promo($this, ['product_id' => $this->fa->soda->public_id, 'percent' => '95'])->assertStatus(422);
});

it('isolamento e permissão: produto de outro tenant e atendente', function () {
    promo($this, ['product_id' => $this->fb->pizza->public_id, 'percent' => '10'])->assertStatus(422);
    expect(inTenant($this->b, fn () => Promotion::query()->count()))->toBe(0);

    $att = merchantLogin($this->a, makeMember($this->a, role: Role::ATTENDANT));
    withSession($att)->getJson(tenantUrl($this->a, 'merchant/promotions'))->assertForbidden();
});
