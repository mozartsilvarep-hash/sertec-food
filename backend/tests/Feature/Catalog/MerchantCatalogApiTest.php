<?php

use App\Modules\Catalog\Models\Product;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Models\Branch;
use Tests\Support\CatalogFixture;

beforeEach(function () {
    $this->a = makeTenant('tenant-a');
    $this->b = makeTenant('tenant-b');
    $this->fa = CatalogFixture::create($this->a);
    $this->fb = CatalogFixture::create($this->b);
    $this->ownerA = makeMember($this->a);
    $this->session = merchantLogin($this->a, $this->ownerA);
});

function productPayload(object $f, array $overrides = []): array
{
    return [
        'name' => 'Calzone',
        'description' => 'Fechado e crocante',
        'active' => true,
        'variants' => [['name' => 'Única', 'price' => '38.90']],
        'option_group_ids' => [$f->crusts->public_id],
        'category_ids' => [$f->pizzas->public_id],
        ...$overrides,
    ];
}

it('cria produto com variantes, grupos e categoria e o devolve com ULIDs', function () {
    $response = withSession($this->session)->postJson(tenantUrl($this->a, 'merchant/products'), productPayload($this->fa))
        ->assertCreated()
        ->assertJsonPath('data.name', 'Calzone')
        ->assertJsonPath('data.variants.0.price', '38.90')
        ->assertJsonPath('data.option_group_ids', [$this->fa->crusts->public_id])
        ->assertJsonPath('data.category_ids', [$this->fa->pizzas->public_id]);

    expect($response->json('data.id'))->toHaveLength(26);
    expect($response->getContent())->not->toContain('"tenant_id"');
});

it('atualiza variantes por ID e remove as que saíram da lista', function () {
    $payload = productPayload($this->fa, ['variants' => [
        ['id' => $this->fa->medium->public_id, 'name' => 'Média', 'price' => '1.00'],
        ['name' => 'Família', 'price' => '9.00'],
    ]]);

    withSession($this->session)->putJson(tenantUrl($this->a, 'merchant/products/'.$this->fa->pizza->public_id), $payload)
        ->assertOk()
        ->assertJsonCount(2, 'data.variants')
        ->assertJsonPath('data.variants.0.id', $this->fa->medium->public_id)
        ->assertJsonPath('data.variants.1.name', 'Família');

    expect(inTenant($this->a, fn () => $this->fa->pizza->variants()->pluck('name')->all()))->toBe(['Média', 'Família']);
});

it('valida preço e exige ao menos uma variante', function () {
    withSession($this->session)->postJson(tenantUrl($this->a, 'merchant/products'), productPayload($this->fa, [
        'variants' => [['name' => 'X', 'price' => '12,50']],
    ]))->assertStatus(422)->assertJsonPath('code', 'validation_failed')
        ->assertJsonStructure(['details' => ['fields' => ['variants.0.price']]]);

    withSession($this->session)->postJson(tenantUrl($this->a, 'merchant/products'), productPayload($this->fa, ['variants' => []]))
        ->assertStatus(422);
});

it('ISOLAMENTO: não referencia categoria, grupo ou variante de outro tenant', function () {
    withSession($this->session)->postJson(tenantUrl($this->a, 'merchant/products'), productPayload($this->fa, [
        'category_ids' => [$this->fb->pizzas->public_id],
    ]))->assertStatus(422)->assertJsonStructure(['details' => ['fields' => ['category_ids']]]);

    withSession($this->session)->postJson(tenantUrl($this->a, 'merchant/products'), productPayload($this->fa, [
        'option_group_ids' => [$this->fb->flavors->public_id],
    ]))->assertStatus(422);

    // Variante de B informada como "id" de variante existente do produto de A.
    withSession($this->session)->putJson(tenantUrl($this->a, 'merchant/products/'.$this->fa->pizza->public_id), productPayload($this->fa, [
        'variants' => [['id' => $this->fb->large->public_id, 'name' => 'Roubada', 'price' => '1.00']],
    ]))->assertStatus(422);

    // Preço de sabor apontando para variante de B.
    withSession($this->session)->putJson(tenantUrl($this->a, 'merchant/option-groups/'.$this->fa->flavors->public_id), [
        'name' => 'Sabores', 'type' => 'fraction', 'min_select' => 1, 'max_select' => 2, 'pricing_rule' => 'highest',
        'options' => [['name' => 'X', 'variant_prices' => [['variant' => $this->fb->large->public_id, 'price' => '1.00']]]],
    ])->assertStatus(422);

    expect(inTenant($this->b, fn () => $this->fb->large->fresh()->name))->toBe('Grande');
});

it('ISOLAMENTO: produto, grupo, categoria e cardápio de B respondem 404 em A', function () {
    $s = withSession($this->session);
    $s->getJson(tenantUrl($this->a, 'merchant/products/'.$this->fb->pizza->public_id))->assertNotFound();
    $s->putJson(tenantUrl($this->a, 'merchant/products/'.$this->fb->pizza->public_id), productPayload($this->fa))->assertNotFound();
    $s->deleteJson(tenantUrl($this->a, 'merchant/products/'.$this->fb->pizza->public_id))->assertNotFound();
    $s->deleteJson(tenantUrl($this->a, 'merchant/option-groups/'.$this->fb->flavors->public_id))->assertNotFound();
    $s->patchJson(tenantUrl($this->a, 'merchant/categories/'.$this->fb->pizzas->public_id), ['name' => 'X'])->assertNotFound();
    $s->deleteJson(tenantUrl($this->a, 'merchant/menus/'.$this->fb->menu->public_id))->assertNotFound();

    $names = collect($s->getJson(tenantUrl($this->a, 'merchant/products'))->json('data'))->pluck('name');
    expect($names->sort()->values()->all())->toBe(['Pizza', 'Refrigerante lata']);

    expect(inTenant($this->b, fn () => Product::query()->count()))->toBe(2);
});

it('lista produtos com paginação por cursor e busca', function () {
    $s = withSession($this->session);
    $first = $s->getJson(tenantUrl($this->a, 'merchant/products?per_page=1'))->assertOk();

    expect($first->json('data'))->toHaveCount(1)
        ->and($first->json('meta.next_cursor'))->not->toBeNull();

    $second = $s->getJson(tenantUrl($this->a, 'merchant/products?per_page=1&cursor='.$first->json('meta.next_cursor')));
    expect($second->json('data.0.name'))->not->toBe($first->json('data.0.name'));

    expect(collect($s->getJson(tenantUrl($this->a, 'merchant/products?search=refri'))->json('data'))->pluck('name')->all())
        ->toBe(['Refrigerante lata']);
});

it('arquiva produto (some do painel e do cardápio, sem apagar)', function () {
    withSession($this->session)->deleteJson(tenantUrl($this->a, 'merchant/products/'.$this->fa->soda->public_id))->assertNoContent();

    expect(inTenant($this->a, fn () => Product::withTrashed()->find($this->fa->soda->id)?->trashed()))->toBeTrue();
    $this->getJson(tenantUrl($this->a, 'products/'.$this->fa->soda->public_id))->assertNotFound();
});

it('grupo de sabores: preço por tamanho e regras do tipo', function () {
    $response = withSession($this->session)->postJson(tenantUrl($this->a, 'merchant/option-groups'), [
        'name' => 'Sabores doces', 'type' => 'fraction', 'min_select' => 1, 'max_select' => 2,
        'pricing_rule' => 'average', 'free_quantity' => 3,
        'options' => [[
            'name' => 'Chocolate', 'price_delta' => '9.99', 'max_quantity' => 5,
            'variant_prices' => [['variant' => $this->fa->medium->public_id, 'price' => '42.00']],
        ]],
    ])->assertCreated();

    // Sabores não têm acréscimo, quantidade > 1 nem unidades grátis.
    $response->assertJsonPath('data.free_quantity', 0)
        ->assertJsonPath('data.options.0.price_delta', '0.00')
        ->assertJsonPath('data.options.0.max_quantity', 1)
        ->assertJsonPath('data.options.0.variant_prices.0.variant', $this->fa->medium->public_id);

    withSession($this->session)->postJson(tenantUrl($this->a, 'merchant/option-groups'), [
        'name' => 'Sabores', 'type' => 'fraction', 'min_select' => 1, 'max_select' => 2,
        'options' => [['name' => 'X']],
    ])->assertStatus(422)->assertJsonStructure(['details' => ['fields' => ['pricing_rule']]]);

    withSession($this->session)->postJson(tenantUrl($this->a, 'merchant/option-groups'), [
        'name' => 'Extras', 'type' => 'addon', 'min_select' => 3, 'max_select' => 1,
        'options' => [['name' => 'X']],
    ])->assertStatus(422)->assertJsonStructure(['details' => ['fields' => ['min_select']]]);
});

it('PERMISSÃO: atendente vê o catálogo mas não altera; marca esgotado só na sua unidade', function () {
    $other = inTenant($this->a, fn () => Branch::query()->create(['name' => 'Jatiúca']));
    $attendant = makeMember($this->a, User::factory()->create(), Role::ATTENDANT, $this->fa->branch);
    $s = withSession(merchantLogin($this->a, $attendant));

    $s->getJson(tenantUrl($this->a, 'merchant/products'))->assertOk();
    $s->postJson(tenantUrl($this->a, 'merchant/products'), productPayload($this->fa))->assertForbidden();
    $s->deleteJson(tenantUrl($this->a, 'merchant/products/'.$this->fa->soda->public_id))->assertForbidden();

    $s->putJson(tenantUrl($this->a, 'merchant/branches/'.$this->fa->branch->public_id.'/availability/'.$this->fa->soda->public_id), ['available' => false])
        ->assertOk()->assertJsonPath('data.available', false);
    $s->putJson(tenantUrl($this->a, 'merchant/branches/'.$other->public_id.'/availability/'.$this->fa->soda->public_id), ['available' => false])
        ->assertNotFound();
    // Produto de outro tenant.
    $s->putJson(tenantUrl($this->a, 'merchant/branches/'.$this->fa->branch->public_id.'/availability/'.$this->fb->soda->public_id), ['available' => false])
        ->assertNotFound();

    $this->getJson(tenantUrl($this->a, 'menus?branch='.$this->fa->branch->public_id))
        ->assertJsonPath('data.menus.0.categories.1.products.0.available', false);
    $this->getJson(tenantUrl($this->a, 'menus?branch='.$other->public_id))
        ->assertJsonPath('data.menus.0.categories.1.products.0.available', true);
});

it('cozinha não vê o catálogo no painel', function () {
    $kitchen = makeMember($this->a, User::factory()->create(), Role::KITCHEN);

    withSession(merchantLogin($this->a, $kitchen))->getJson(tenantUrl($this->a, 'merchant/products'))->assertForbidden();
});

it('cardápio e categoria: criar, mover e apagar dentro do tenant', function () {
    $s = withSession($this->session);
    $menu = $s->postJson(tenantUrl($this->a, 'merchant/menus'), ['name' => 'Almoço', 'branch_id' => $this->fa->branch->public_id])
        ->assertCreated()->assertJsonPath('data.branch_id', $this->fa->branch->public_id)->json('data.id');

    $s->postJson(tenantUrl($this->a, 'merchant/menus'), ['name' => 'X', 'branch_id' => $this->fb->branch->public_id])->assertStatus(422);

    $category = $s->postJson(tenantUrl($this->a, 'merchant/categories'), ['menu_id' => $menu, 'name' => 'Pratos'])
        ->assertCreated()->json('data.id');
    $s->patchJson(tenantUrl($this->a, 'merchant/categories/'.$category), ['menu_id' => $this->fb->menu->public_id])->assertStatus(422);
    $s->getJson(tenantUrl($this->a, 'merchant/categories?menu='.$menu))->assertJsonCount(1, 'data');

    $s->deleteJson(tenantUrl($this->a, 'merchant/menus/'.$menu))->assertNoContent();
    $s->getJson(tenantUrl($this->a, 'merchant/categories?menu='.$menu))->assertStatus(422);
});
