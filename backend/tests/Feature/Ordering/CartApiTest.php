<?php

use App\Modules\Catalog\Models\BranchProductAvailability;
use App\Modules\Ordering\Models\Cart;
use Tests\Support\CatalogFixture;
use Tests\Support\Shop;

beforeEach(function () {
    $this->a = makeTenant('tenant-a');
    $this->b = makeTenant('tenant-b');
    $this->fa = CatalogFixture::create($this->a);
    $this->fb = CatalogFixture::create($this->b);
});

it('cria carrinho com token de 48 hex e guarda só o hash', function () {
    $response = $this->postJson(tenantUrl($this->a, 'carts'))->assertCreated();
    $token = $response->json('data.token');

    expect($token)->toMatch('/^[a-f0-9]{48}$/')
        ->and($response->json('data.branch_id'))->toBe($this->fa->branch->public_id)
        ->and(inTenant($this->a, fn () => Cart::query()->sole()->token_hash))->toBe(hash('sha256', $token));

    $this->getJson(tenantUrl($this->a, "carts/{$token}"))->assertOk()->assertJsonMissingPath('data.token');
});

it('preço do carrinho é calculado no servidor a cada leitura', function () {
    $token = Shop::newCart($this->a);
    Shop::addPizza($this->fa, $token, 2, ['Calabresa' => 1, 'Portuguesa' => 1], ['Catupiry' => 1])->assertCreated()
        ->assertJsonPath('data.items.0.unit_price', '45.01')   // 1 borda grátis
        ->assertJsonPath('data.items.0.line_total', '90.02');
    Shop::addSoda($this->fa, $token, 3)->assertJsonPath('data.subtotal', '109.52')->assertJsonPath('data.count', 5);

    inTenant($this->a, fn () => $this->fa->sodaVariant->update(['price' => '7.00']));
    $this->getJson(tenantUrl($this->a, "carts/{$token}"))->assertJsonPath('data.subtotal', '111.02');
});

it('recusa configuração inválida ao adicionar', function () {
    $token = Shop::newCart($this->a);

    Shop::addPizza($this->fa, $token, 1, [])->assertStatus(422)->assertJsonPath('details.reason', 'group_min_not_met');
    $this->postJson(tenantUrl($this->a, "carts/{$token}/items"), [
        'product_id' => $this->fa->pizza->public_id, 'variant_id' => $this->fa->medium->public_id,
        'options' => [['option' => $this->fa->flavor['Calabresa']->public_id, 'quantity' => 1]], 'quantity' => 0,
    ])->assertStatus(422)->assertJsonPath('code', 'validation_failed');
});

it('altera quantidade e observação, remove item e zera removendo', function () {
    $token = Shop::newCart($this->a);
    $item = Shop::addSoda($this->fa, $token)->json('data.items.0.id');

    $this->patchJson(tenantUrl($this->a, "carts/{$token}/items/{$item}"), ['quantity' => 4, 'notes' => 'Bem gelado'])
        ->assertOk()->assertJsonPath('data.items.0.quantity', 4)->assertJsonPath('data.items.0.notes', 'Bem gelado');
    $this->patchJson(tenantUrl($this->a, "carts/{$token}/items/{$item}"), ['quantity' => 0])->assertJsonCount(0, 'data.items');

    $item = Shop::addSoda($this->fa, $token)->json('data.items.0.id');
    $this->deleteJson(tenantUrl($this->a, "carts/{$token}/items/{$item}"))->assertJsonCount(0, 'data.items');
});

it('item que esgotou continua visível, marcado como inválido', function () {
    $token = Shop::newCart($this->a);
    Shop::addSoda($this->fa, $token);
    inTenant($this->a, fn () => BranchProductAvailability::query()->create([
        'branch_id' => $this->fa->branch->id, 'product_id' => $this->fa->soda->id, 'available' => false,
    ]));

    $this->getJson(tenantUrl($this->a, "carts/{$token}"))
        ->assertJsonPath('data.valid', false)
        ->assertJsonPath('data.items.0.valid', false)
        ->assertJsonPath('data.items.0.problem.reason', 'product_sold_out')
        ->assertJsonPath('data.subtotal', '0.00');
});

it('carrinho expirado ou token malformado responde 404', function () {
    $token = Shop::newCart($this->a);
    inTenant($this->a, fn () => Cart::query()->update(['expires_at' => now()->subMinute()]));

    $this->getJson(tenantUrl($this->a, "carts/{$token}"))->assertNotFound()->assertJsonPath('code', 'cart_not_found');
    $this->getJson(tenantUrl($this->a, 'carts/nao-e-token'))->assertNotFound();
});

it('ISOLAMENTO: carrinho de A não existe no host de B; produto de B não entra no carrinho de A', function () {
    $token = Shop::newCart($this->a);
    $item = Shop::addSoda($this->fa, $token)->json('data.items.0.id');

    $this->getJson(tenantUrl($this->b, "carts/{$token}"))->assertNotFound();
    $this->deleteJson(tenantUrl($this->b, "carts/{$token}/items/{$item}"))->assertNotFound();

    $this->postJson(tenantUrl($this->a, "carts/{$token}/items"), [
        'product_id' => $this->fb->soda->public_id, 'variant_id' => $this->fb->sodaVariant->public_id, 'options' => [], 'quantity' => 1,
    ])->assertStatus(422)->assertJsonPath('details.reason', 'product_unavailable');

    // Item de outro carrinho do mesmo tenant.
    $other = Shop::newCart($this->a);
    $this->deleteJson(tenantUrl($this->a, "carts/{$other}/items/{$item}"))->assertNotFound()->assertJsonPath('code', 'cart_item_not_found');
});
