<?php

use App\Modules\Catalog\Models\BranchProductAvailability;
use App\Modules\Catalog\Models\OptionGroup;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Support\ProductSchedule;
use App\Modules\Ordering\Models\Order;
use Carbon\CarbonImmutable;
use Tests\Support\CatalogFixture;
use Tests\Support\Shop;

beforeEach(function () {
    $this->a = makeTenant('tenant-a');
    $this->b = makeTenant('tenant-b');
    $this->fa = CatalogFixture::create($this->a);
    $this->fb = CatalogFixture::create($this->b);
    $this->session = merchantLogin($this->a, makeMember($this->a));
});

afterEach(fn () => CarbonImmutable::setTestNow());

function saveProduct(object $t, array $body, ?string $id = null)
{
    return withSession($t->session)->{$id ? 'putJson' : 'postJson'}(tenantUrl($t->a, 'merchant/products'.($id ? "/{$id}" : '')), $body + [
        'active' => true,
        'category_ids' => [$t->fa->drinks->public_id],
    ]);
}

function menuProduct(object $t, string $id): array
{
    $menus = test()->getJson(tenantUrl($t->a, 'menus'))->assertOk()->json('data.menus');

    return collect($menus)->flatMap(fn ($m) => $m['categories'])->flatMap(fn ($c) => $c['products'])->firstWhere('id', $id);
}

/* ---------- Dias e horários ---------- */

it('rótulos de disponibilidade em português', function () {
    $label = fn (array $attrs) => inTenant($this->a, fn () => ProductSchedule::for(new Product($attrs))->label());

    expect($label([]))->toBeNull()
        ->and($label(['available_days' => [0, 6]]))->toBe('Só sáb. e dom.')
        ->and($label(['available_days' => [1, 2, 3, 4, 5]]))->toBe('Só de seg. a sex.')
        ->and($label(['available_days' => [1, 3, 5]]))->toBe('Só seg., qua. e sex.')
        ->and($label(['available_days' => [2, 3, 4, 6]]))->toBe('Só ter. a qui. e sáb.')
        ->and($label(['available_from' => '11:00:00', 'available_until' => '15:30:00']))->toBe('Das 11h às 15h30')
        ->and($label(['available_days' => [5, 6], 'available_from' => '18:00', 'available_until' => '23:00']))->toBe('Só sex. e sáb., das 18h às 23h');
});

it('produto só no fim de semana: aparece com aviso e é recusado no carrinho fora do dia', function () {
    $id = saveProduct($this, ['name' => 'Bolo de rolo', 'variants' => [['name' => 'Fatia', 'price' => '12.00']], 'available_days' => [0, 6]])
        ->assertCreated()->assertJsonPath('data.availability', 'Só sáb. e dom.')->json('data.id');

    // Segunda-feira, 26/10/2026, 15h em São Paulo.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-26 15:00', 'America/Sao_Paulo'));
    expect(menuProduct($this, $id))->toMatchArray(['available' => false, 'availability' => 'Só sáb. e dom.']);

    $product = inTenant($this->a, fn () => Product::query()->where('public_id', $id)->with('variants')->sole());
    $add = fn (string $token) => $this->postJson(tenantUrl($this->a, "carts/{$token}/items"), ['product_id' => $id, 'variant_id' => $product->variants[0]->public_id, 'options' => [], 'quantity' => 1]);
    $add(Shop::newCart($this->a))->assertStatus(422)->assertJsonPath('details.reason', 'product_not_available_now')
        ->assertJsonPath('message', '"Bolo de rolo" está disponível só sáb. e dom.');

    // Domingo à noite: liberado.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-01 23:50', 'America/Sao_Paulo'));
    expect(menuProduct($this, $id)['available'])->toBeTrue();
    $token = Shop::newCart($this->a);
    $add($token)->assertCreated();

    // Item que já estava no carrinho fica inválido se o dia acabar antes do checkout.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-02 00:10', 'America/Sao_Paulo'));
    Shop::checkout($this->a, Shop::pickupPayload($token))->assertStatus(422)->assertJsonPath('code', 'cart_invalid');
});

it('horário que passa da meia-noite conta para o dia em que começou', function () {
    $id = saveProduct($this, ['name' => 'Caldo', 'variants' => [['name' => 'Único', 'price' => '15.00']], 'available_days' => [5], 'available_from' => '20:00', 'available_until' => '02:00'])
        ->assertCreated()->json('data.id');

    $at = function (string $local) use ($id) {
        CarbonImmutable::setTestNow(CarbonImmutable::parse($local, 'America/Sao_Paulo'));

        return menuProduct($this, $id)['available'];
    };
    expect($at('2026-10-30 21:00'))->toBeTrue()   // sexta 21h
        ->and($at('2026-10-31 01:30'))->toBeTrue()    // sábado 1h30 (noite de sexta)
        ->and($at('2026-10-31 21:00'))->toBeFalse()   // sábado 21h
        ->and($at('2026-10-30 19:59'))->toBeFalse();  // sexta antes de abrir
});

it('todos os dias marcados = sem restrição; horário pela metade é recusado', function () {
    saveProduct($this, ['name' => 'Água', 'variants' => [['name' => 'Única', 'price' => '3.00']], 'available_days' => [0, 1, 2, 3, 4, 5, 6]])
        ->assertCreated()->assertJsonPath('data.available_days', null)->assertJsonPath('data.availability', null);
    saveProduct($this, ['name' => 'Suco', 'variants' => [['name' => 'Único', 'price' => '8.00']], 'available_from' => '10:00'])
        ->assertStatus(422)->assertJsonPath('details.fields.available_until.0', 'Preencha o campo fim do horário.');
});

/* ---------- Combos ---------- */

function makeCombo(object $t, array $slots = []): string
{
    $sodaId = $t->fa->soda->public_id;
    $pizzaId = $t->fa->pizza->public_id;

    return saveProduct($t, [
        'name' => 'Combo Família',
        'kind' => 'combo',
        'variants' => [['name' => 'Único', 'price' => '45.00']],
        'combo_slots' => $slots ?: [
            ['name' => 'Escolha 2 refrigerantes', 'quantity' => 2, 'product_ids' => [$sodaId]],
            ['name' => 'Escolha 1 pizza', 'quantity' => 1, 'product_ids' => [$pizzaId]],
        ],
    ])->assertCreated()->json('data.id');
}

it('combo: espaços com produtos do cardápio, preço fixo, "de" pela soma dos avulsos', function () {
    $id = makeCombo($this);

    withSession($this->session)->getJson(tenantUrl($this->a, "merchant/products/{$id}"))->assertOk()
        ->assertJsonPath('data.kind', 'combo')
        ->assertJsonPath('data.option_group_ids', [])
        ->assertJsonPath('data.combo_slots.0.name', 'Escolha 2 refrigerantes')
        ->assertJsonPath('data.combo_slots.0.quantity', 2)
        ->assertJsonPath('data.combo_slots.0.product_ids', [$this->fa->soda->public_id]);

    // Grupos do combo não aparecem na lista geral nem podem ser editados por fora.
    $groups = withSession($this->session)->getJson(tenantUrl($this->a, 'merchant/option-groups'))->assertOk()->json('data');
    expect(collect($groups)->pluck('name')->all())->not->toContain('Escolha 2 refrigerantes');
    $owned = inTenant($this->a, fn () => OptionGroup::query()->whereNotNull('owner_product_id')->first());
    withSession($this->session)->getJson(tenantUrl($this->a, "merchant/option-groups/{$owned->public_id}"))->assertNotFound();

    // Soma dos avulsos: 2 × 6,50 + pizza (variante mais barata, 0,00) = 13,00.
    expect(menuProduct($this, $id))->toMatchArray(['kind' => 'combo', 'price_from' => '45.00', 'compare_at' => '13.00']);

    $detail = $this->getJson(tenantUrl($this->a, "products/{$id}"))->assertOk()->json('data');
    $slot = collect($detail['option_groups'])->firstWhere('name', 'Escolha 2 refrigerantes');
    expect($slot)->toMatchArray(['min_select' => 2, 'max_select' => 2, 'required' => true])
        ->and($slot['options'][0])->toMatchArray(['name' => 'Refrigerante lata', 'price_delta' => '0.00', 'max_quantity' => 2, 'available' => true]);
    $pizzaSlot = collect($detail['option_groups'])->firstWhere('name', 'Escolha 1 pizza');

    $token = Shop::newCart($this->a);
    $add = fn (int $sodas) => $this->postJson(tenantUrl($this->a, "carts/{$token}/items"), [
        'product_id' => $id, 'variant_id' => $detail['variants'][0]['id'], 'quantity' => 1,
        'options' => [['option' => $slot['options'][0]['id'], 'quantity' => $sodas], ['option' => $pizzaSlot['options'][0]['id'], 'quantity' => 1]],
    ]);
    $add(1)->assertStatus(422)->assertJsonPath('details.reason', 'group_min_not_met');
    $add(2)->assertCreated()->assertJsonPath('data.items.0.unit_price', '45.00');

    Shop::checkout($this->a, Shop::pickupPayload($token))->assertCreated()->assertJsonPath('data.total', '45.00');
    $order = inTenant($this->a, fn () => Order::query()->with('items.options')->sole());
    expect($order->items[0]->product_name_snapshot)->toBe('Combo Família')
        ->and($order->items[0]->options->pluck('quantity', 'option_name_snapshot')->all())->toBe(['Refrigerante lata' => 2, 'Pizza' => 1]);
});

it('combo: item esgotado na unidade não pode ser escolhido', function () {
    $id = makeCombo($this);
    inTenant($this->a, fn () => BranchProductAvailability::query()->create(['branch_id' => $this->fa->branch->id, 'product_id' => $this->fa->soda->id, 'available' => false]));

    $detail = $this->getJson(tenantUrl($this->a, "products/{$id}"))->assertOk()->json('data');
    $slot = collect($detail['option_groups'])->firstWhere('name', 'Escolha 2 refrigerantes');
    $pizzaSlot = collect($detail['option_groups'])->firstWhere('name', 'Escolha 1 pizza');
    expect($slot['options'][0]['available'])->toBeFalse();

    $token = Shop::newCart($this->a);
    $this->postJson(tenantUrl($this->a, "carts/{$token}/items"), [
        'product_id' => $id, 'variant_id' => $detail['variants'][0]['id'], 'quantity' => 1,
        'options' => [['option' => $slot['options'][0]['id'], 'quantity' => 2], ['option' => $pizzaSlot['options'][0]['id'], 'quantity' => 1]],
    ])->assertStatus(422)->assertJsonPath('details.reason', 'option_invalid')
        ->assertJsonPath('message', '"Refrigerante lata" não está disponível agora. Escolha outro.');
});

it('combo: não aceita produto de outro restaurante, a si mesmo ou outro combo; editar mantém os espaços', function () {
    $foreign = $this->fb->soda->public_id;
    saveProduct($this, ['name' => 'Combo X', 'kind' => 'combo', 'variants' => [['name' => 'Único', 'price' => '10.00']],
        'combo_slots' => [['name' => 'Bebida', 'quantity' => 1, 'product_ids' => [$foreign]]]])->assertStatus(422);
    saveProduct($this, ['name' => 'Combo Y', 'kind' => 'combo', 'variants' => [['name' => 'Único', 'price' => '10.00']]])
        ->assertStatus(422)->assertJsonPath('details.fields.combo_slots.0', 'Um combo precisa de ao menos um item (ex.: "2 bolos de pote").');

    $id = makeCombo($this);
    saveProduct($this, ['name' => 'Combo Duplo', 'kind' => 'combo', 'variants' => [['name' => 'Único', 'price' => '80.00']],
        'combo_slots' => [['name' => 'Combo', 'quantity' => 1, 'product_ids' => [$id]]]])->assertStatus(422);

    $current = withSession($this->session)->getJson(tenantUrl($this->a, "merchant/products/{$id}"))->json('data');
    $slots = $current['combo_slots'];
    $slots[0]['quantity'] = 3;
    $slots[0]['name'] = 'Escolha 3 refrigerantes';
    saveProduct($this, ['name' => 'Combo Família', 'kind' => 'combo', 'variants' => [['id' => $current['variants'][0]['id'], 'name' => 'Único', 'price' => '49.00']], 'combo_slots' => [$slots[0]]], $id)
        ->assertOk()->assertJsonPath('data.combo_slots.0.id', $slots[0]['id'])->assertJsonPath('data.combo_slots.0.quantity', 3)
        ->assertJsonCount(1, 'data.combo_slots');
    expect(inTenant($this->a, fn () => OptionGroup::query()->whereNotNull('owner_product_id')->count()))->toBe(1);
});

/* ---------- Leve N, pague M ---------- */

function bundle(object $t, array $body)
{
    return withSession($t->session)->postJson(tenantUrl($t->a, 'merchant/promotions'), $body);
}

it('"compre 1, leve outro": unidades mais baratas saem de graça no carrinho e no pedido', function () {
    bundle($this, ['product_id' => $this->fa->soda->public_id, 'type' => 'bundle', 'buy_quantity' => 2, 'pay_quantity' => 1])
        ->assertCreated()->assertJsonPath('data.label', 'Leve 2, pague 1')->assertJsonPath('data.percent', null);

    expect(menuProduct($this, $this->fa->soda->public_id))->toMatchArray(['promo_price_from' => null])
        ->and(menuProduct($this, $this->fa->soda->public_id)['promotion'])->toMatchArray(['type' => 'bundle', 'buy_quantity' => 2, 'pay_quantity' => 1, 'label' => 'Leve 2, pague 1']);

    $token = Shop::newCart($this->a);
    Shop::addSoda($this->fa, $token, 3)->assertCreated()
        ->assertJsonPath('data.items.0.unit_price', '6.50')
        ->assertJsonPath('data.subtotal', '19.50')
        ->assertJsonPath('data.discounts.0', ['label' => 'Leve 2, pague 1 · Refrigerante lata', 'amount' => '6.50'])
        ->assertJsonPath('data.net_subtotal', '13.00');

    Shop::checkout($this->a, Shop::pickupPayload($token))->assertCreated()
        ->assertJsonPath('data.total', '13.00')->assertJsonPath('data.discount_total', '6.50');
    $order = inTenant($this->a, fn () => Order::query()->with('totals')->sole());
    expect($order->totals->pluck('amount', 'code')->all())->toBe(['subtotal' => '19.50', 'promotion' => '6.50', 'total' => '13.00']);
});

it('leve/pague inválido e sobreposição com promoção percentual são recusados', function () {
    bundle($this, ['product_id' => $this->fa->soda->public_id, 'type' => 'bundle', 'buy_quantity' => 2, 'pay_quantity' => 2])
        ->assertStatus(422)->assertJsonPath('details.fields.pay_quantity.0', 'Em "leve N, pague M", o "pague" precisa ser menor que o "leve" (ex.: leve 2, pague 1).');
    bundle($this, ['product_id' => $this->fa->soda->public_id, 'percent' => '10'])->assertCreated();
    bundle($this, ['product_id' => $this->fa->soda->public_id, 'type' => 'bundle', 'buy_quantity' => 3, 'pay_quantity' => 2])
        ->assertStatus(409)->assertJsonPath('code', 'promotion_overlap');
});

it('feed do site: "leve N, pague M" vai com selo e sem preço "de"', function () {
    bundle($this, ['product_id' => $this->fa->soda->public_id, 'type' => 'bundle', 'buy_quantity' => 3, 'pay_quantity' => 2])->assertCreated();
    $item = $this->getJson(tenantUrl($this->a, 'vitrine/promocoes.json'))->assertOk()->json('promocoes.0');
    expect($item)->toMatchArray(['titulo' => 'Refrigerante lata', 'precoPor' => 6.5, 'selo' => 'Leve 3, pague 2'])
        ->and($item)->not->toHaveKey('precoDe');
});
