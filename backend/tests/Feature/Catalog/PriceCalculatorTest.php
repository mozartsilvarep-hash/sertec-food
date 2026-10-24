<?php

use App\Modules\Catalog\Models\BranchProductAvailability;
use App\Modules\Catalog\Pricing\PriceCalculator;
use App\Modules\Catalog\Pricing\PricingException;
use Tests\Support\CatalogFixture;

beforeEach(function () {
    $this->tenant = makeTenant('pizzaria');
    $this->f = CatalogFixture::create($this->tenant);
    $this->calc = new PriceCalculator;
});

function quote(object $test, string $variantId, array $selections, $branch = null): array
{
    return inTenant($test->tenant, fn () => $test->calc
        ->quote($test->f->pizza->fresh(), $variantId, $selections, $branch)->toArray());
}

function quoteError(object $test, string $variantId, array $selections, $branch = null): string
{
    try {
        quote($test, $variantId, $selections, $branch);
    } catch (PricingException $e) {
        return $e->reason;
    }

    return 'nenhum erro';
}

it('produto simples: preço da variante', function () {
    $q = inTenant($this->tenant, fn () => $this->calc->quote($this->f->soda->fresh(), $this->f->sodaVariant->public_id, [])->toArray());

    expect($q['unit_price'])->toBe('6.50')->and($q['options'])->toBe([]);
});

it('meio a meio cobra o sabor mais caro (regra padrão)', function () {
    $q = quote($this, $this->f->medium->public_id, $this->f->pick(['Calabresa' => 1, 'Portuguesa' => 1]));

    expect($q['fraction_price'])->toBe('45.01')
        ->and($q['unit_price'])->toBe('45.01');
});

it('meio a meio pela média arredonda meio centavo para cima', function () {
    inTenant($this->tenant, fn () => $this->f->flavors->update(['pricing_rule' => 'average']));

    // (40.00 + 45.01) / 2 = 42.505 → 42.51
    $q = quote($this, $this->f->medium->public_id, $this->f->pick(['Calabresa' => 1, 'Portuguesa' => 1]));

    expect($q['unit_price'])->toBe('42.51');
});

it('soma o preço base do tamanho ao preço dos sabores', function () {
    $q = quote($this, $this->f->large->public_id, $this->f->pick(['Calabresa' => 1]));

    expect($q['base_price'])->toBe('5.00')->and($q['unit_price'])->toBe('55.00');
});

it('sabor sem preço no tamanho escolhido é recusado', function () {
    expect(quoteError($this, $this->f->medium->public_id, $this->f->pick(['Camarão' => 1])))
        ->toBe('flavor_unavailable_for_size');

    expect(quote($this, $this->f->large->public_id, $this->f->pick(['Camarão' => 1, 'Calabresa' => 1]))['unit_price'])
        ->toBe('85.00');
});

it('adicionais: soma price_delta × quantidade e a unidade grátis é a mais barata', function () {
    // Catupiry 8.00 + Bacon 2 × 4.50; 1 grátis = um Bacon → 8.00 + 4.50
    $q = quote($this, $this->f->medium->public_id, $this->f->pick(['Calabresa' => 1], ['Catupiry' => 1, 'Bacon' => 2]));

    $bacon = collect($q['options'])->firstWhere('option_name', 'Bacon');
    expect($bacon['charged_quantity'])->toBe(1)
        ->and($bacon['total'])->toBe('4.50')
        ->and($q['unit_price'])->toBe('52.50');
});

it('respeita mínimo, máximo e obrigatoriedade dos grupos', function () {
    expect(quoteError($this, $this->f->medium->public_id, []))->toBe('group_min_not_met');
    expect(quoteError($this, $this->f->large->public_id, $this->f->pick(['Calabresa' => 1, 'Portuguesa' => 1, 'Camarão' => 1])))
        ->toBe('group_max_exceeded');
    expect(quoteError($this, $this->f->medium->public_id, $this->f->pick(['Calabresa' => 1], ['Catupiry' => 2, 'Bacon' => 3])))
        ->toBe('group_max_exceeded');
});

it('recusa quantidade acima do limite da opção e sabor repetido', function () {
    expect(quoteError($this, $this->f->medium->public_id, $this->f->pick(['Calabresa' => 1], ['Cheddar' => 3])))
        ->toBe('option_quantity_invalid');
    expect(quoteError($this, $this->f->medium->public_id, $this->f->pick(['Calabresa' => 2])))
        ->toBe('option_quantity_invalid');

    $dup = [...$this->f->pick(['Calabresa' => 1]), ...$this->f->pick(['Calabresa' => 1])];
    expect(quoteError($this, $this->f->medium->public_id, $dup))->toBe('option_duplicated');
});

it('recusa variante de outro produto e opção de outro produto/tenant', function () {
    expect(quoteError($this, $this->f->sodaVariant->public_id, $this->f->pick(['Calabresa' => 1])))->toBe('variant_invalid');

    $other = CatalogFixture::create(makeTenant('outra-pizzaria'));
    $foreign = [['option' => $other->flavor['Calabresa']->public_id, 'quantity' => 1]];

    expect(quoteError($this, $this->f->medium->public_id, $foreign))->toBe('option_invalid');
    expect(quoteError($this, $this->f->medium->public_id, [['option' => '01HZZZZZZZZZZZZZZZZZZZZZZZ']]))->toBe('option_invalid');
});

it('opção ou produto inativo e produto esgotado na unidade não são vendidos', function () {
    inTenant($this->tenant, fn () => $this->f->flavor['Portuguesa']->update(['active' => false]));
    expect(quoteError($this, $this->f->medium->public_id, $this->f->pick(['Portuguesa' => 1])))->toBe('option_invalid');

    inTenant($this->tenant, fn () => BranchProductAvailability::query()->create([
        'branch_id' => $this->f->branch->id, 'product_id' => $this->f->pizza->id, 'available' => false,
    ]));
    expect(quoteError($this, $this->f->medium->public_id, $this->f->pick(['Calabresa' => 1]), $this->f->branch))->toBe('product_sold_out');

    inTenant($this->tenant, fn () => $this->f->pizza->update(['active' => false]));
    expect(quoteError($this, $this->f->medium->public_id, $this->f->pick(['Calabresa' => 1])))->toBe('product_unavailable');
});

it('o erro de seleção vira 422 invalid_selection no formato padrão', function () {
    $e = new PricingException('group_min_not_met', 'x', ['group' => 'g']);

    expect($e->getStatusCode())->toBe(422)
        ->and($e->errorCode)->toBe('invalid_selection')
        ->and($e->details)->toBe(['reason' => 'group_min_not_met', 'group' => 'g']);
});
