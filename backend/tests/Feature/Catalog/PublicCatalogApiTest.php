<?php

use App\Modules\Tenancy\Models\Branch;
use App\Modules\Tenancy\Models\BranchOpeningHour;
use Carbon\CarbonImmutable;
use Tests\Support\CatalogFixture;

beforeEach(function () {
    $this->a = makeTenant('tenant-a');
    $this->b = makeTenant('tenant-b');
    $this->fa = CatalogFixture::create($this->a);
    $this->fb = CatalogFixture::create($this->b);
});

it('devolve a árvore do cardápio só com itens ativos e preço "a partir de"', function () {
    inTenant($this->a, fn () => $this->fa->drinks->update(['active' => false]));

    $data = $this->getJson(tenantUrl($this->a, 'menus'))->assertOk()->json('data');

    expect($data['branch_id'])->toBe($this->fa->branch->public_id)
        ->and($data['menus'])->toHaveCount(1)
        ->and(collect($data['menus'][0]['categories'])->pluck('name')->all())->toBe(['Pizzas']);

    $pizza = $data['menus'][0]['categories'][0]['products'][0];
    // Média (0.00) + sabor mais barato (Calabresa 40.00); bordas não obrigatórias.
    expect($pizza)->toMatchArray(['name' => 'Pizza', 'price_from' => '40.00', 'has_options' => true, 'available' => true]);
});

it('detalhe do produto traz grupos, opções e preço do sabor por tamanho', function () {
    $data = $this->getJson(tenantUrl($this->a, 'products/'.$this->fa->pizza->public_id))->assertOk()->json('data');

    $flavors = collect($data['option_groups'])->firstWhere('type', 'fraction');
    $shrimp = collect($flavors['options'])->firstWhere('name', 'Camarão');

    expect($data['variants'])->toHaveCount(2)
        ->and($flavors['required'])->toBeTrue()
        ->and($shrimp['prices'])->toBe([$this->fa->large->public_id => '80.00']);
});

it('ISOLAMENTO: produto e unidade de B não aparecem no host de A', function () {
    $this->getJson(tenantUrl($this->a, 'products/'.$this->fb->pizza->public_id))->assertNotFound();
    $this->getJson(tenantUrl($this->a, 'menus?branch='.$this->fb->branch->public_id))->assertNotFound();

    $body = $this->getJson(tenantUrl($this->a, 'menus'))->getContent();
    expect($body)->not->toContain($this->fb->pizza->public_id);
});

it('produto inativo ou unidade inativa respondem 404', function () {
    inTenant($this->a, fn () => $this->fa->pizza->update(['active' => false]));
    $this->getJson(tenantUrl($this->a, 'products/'.$this->fa->pizza->public_id))->assertNotFound();

    inTenant($this->a, fn () => $this->fa->branch->update(['active' => false]));
    $this->getJson(tenantUrl($this->a, 'menus?branch='.$this->fa->branch->public_id))->assertNotFound();
});

it('storefront informa marca e se cada unidade está aberta agora', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-23 19:00', 'America/Sao_Paulo'));
    inTenant($this->a, function () {
        $this->a->update(['brand_color' => '#9a3412', 'description' => 'A melhor da Pajuçara']);
        BranchOpeningHour::query()->create(['branch_id' => $this->fa->branch->id, 'weekday' => 5, 'opens_at' => '18:00', 'closes_at' => '23:00']);
        Branch::query()->create(['name' => 'Sem horário']);
    });

    $data = $this->getJson(tenantUrl($this->a, 'storefront'))->assertOk()->json('data');

    expect($data['brand_color'])->toBe('#9a3412')
        ->and($data['branches'][0])->toMatchArray(['name' => 'Centro', 'open' => true, 'has_hours' => true])
        ->and($data['branches'][0]['closes_at'])->toBe('2026-10-23T23:00:00-03:00')
        ->and($data['branches'][1])->toMatchArray(['name' => 'Sem horário', 'open' => false, 'has_hours' => false]);
});
