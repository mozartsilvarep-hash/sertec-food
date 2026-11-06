<?php

use App\Modules\Catalog\Models\ProductStock;
use App\Modules\Identity\Models\Role;
use Illuminate\Support\Str;
use Tests\Support\CatalogFixture;
use Tests\Support\Shop;

beforeEach(function () {
    $this->a = makeTenant('tenant-a');
    $this->b = makeTenant('tenant-b');
    $this->fa = CatalogFixture::create($this->a);
    $this->fb = CatalogFixture::create($this->b);
    withCashbook($this->a);
    $this->owner = makeMember($this->a);
    $this->session = merchantLogin($this->a, $this->owner);
    $this->branch = $this->fa->branch->public_id;
    $this->soda = $this->fa->soda->public_id;
});

function stockApi(object $t, string $method, string $path, array $body = [], ?string $session = null)
{
    $url = tenantUrl($t->a, 'merchant/'.$path);

    return withSession($session ?? $t->session)->json($method, $method === 'GET' ? $url.(str_contains($url, '?') ? '&' : '?').'branch='.$t->branch : $url, ['branch' => $t->branch] + $body);
}

function sodaSale(object $t, int $qty, bool $kitchen = false)
{
    return withSession($t->session)->withHeader('Idempotency-Key', (string) Str::uuid())->postJson(tenantUrl($t->a, 'merchant/cash/pos/sales'), [
        'branch' => $t->branch,
        'items' => [['product_id' => $t->soda, 'variant_id' => $t->fa->sodaVariant->public_id, 'quantity' => $qty]],
        'payments' => [['method' => 'cash', 'amount' => number_format(6.5 * $qty, 2, ',', '')]],
        'send_to_kitchen' => $kitchen,
    ]);
}

function sodaRow(object $t): array
{
    return collect(stockApi($t, 'GET', 'stock')->assertOk()->json('data.products'))->firstWhere('id', $t->soda);
}

it('PDV baixa o estoque; sem saldo recusa; zerado vira esgotado e volta ao cancelar', function () {
    withSession($this->session)->postJson(tenantUrl($this->a, 'merchant/cash/session'), ['branch' => $this->branch, 'opening_amount' => '0'])->assertCreated();
    stockApi($this, 'PUT', "stock/{$this->soda}", ['tracked' => true, 'quantity' => 3, 'min_quantity' => 1])->assertOk();
    expect(sodaRow($this))->toMatchArray(['tracked' => true, 'quantity' => 3, 'low' => false, 'sold_out' => false]);

    sodaSale($this, 2)->assertCreated();
    expect(sodaRow($this))->toMatchArray(['quantity' => 1, 'low' => true]);
    sodaSale($this, 2)->assertStatus(422)->assertJsonPath('code', 'out_of_stock')->assertJsonPath('message', 'Só restam 1 de Refrigerante lata.');

    $last = sodaSale($this, 1, kitchen: true)->assertCreated()->json('data.order_id');
    expect(sodaRow($this))->toMatchArray(['quantity' => 0, 'sold_out' => true]);
    // Esgotado no cardápio da loja também.
    Shop::addSoda($this->fa, Shop::newCart($this->a))->assertStatus(422);

    withSession($this->session)->patchJson(tenantUrl($this->a, "merchant/orders/{$last}/status"), ['status' => 'cancelled', 'reason' => 'customer_request'])->assertOk();
    expect(sodaRow($this))->toMatchArray(['quantity' => 1, 'sold_out' => false]);

    $moves = stockApi($this, 'GET', "stock/{$this->soda}/movements")->assertOk()->json('data');
    expect(array_column($moves, 'type'))->toBe(['cancel', 'sale', 'sale', 'count'])
        ->and(array_column($moves, 'balance_after'))->toBe([1, 0, 1, 3]);
});

it('loja online baixa no pedido e devolve quando o restaurante recusa', function () {
    stockApi($this, 'PUT', "stock/{$this->soda}", ['tracked' => true, 'quantity' => 5])->assertOk();
    $token = Shop::newCart($this->a);
    Shop::addSoda($this->fa, $token, 3)->assertCreated();
    $order = Shop::checkout($this->a, Shop::pickupPayload($token, ['payment' => ['method' => 'cash']]))->assertCreated()->json('data.order_id');
    expect(sodaRow($this)['quantity'])->toBe(2);

    // Outro cliente quer 3: não há.
    $token2 = Shop::newCart($this->a);
    Shop::addSoda($this->fa, $token2, 3)->assertCreated();
    Shop::checkout($this->a, Shop::pickupPayload($token2, ['payment' => ['method' => 'cash']]))->assertStatus(422)->assertJsonPath('code', 'out_of_stock');

    withSession($this->session)->patchJson(tenantUrl($this->a, "merchant/orders/{$order}/status"), ['status' => 'rejected', 'reason' => 'other'])->assertOk();
    expect(sodaRow($this)['quantity'])->toBe(5);
});

it('ajustes: entrada, perda e contagem; esgotado à mão não é liberado pelo estoque', function () {
    stockApi($this, 'PUT', "stock/{$this->soda}", ['tracked' => true, 'quantity' => 0])->assertOk();
    expect(sodaRow($this)['sold_out'])->toBeTrue();
    stockApi($this, 'POST', "stock/{$this->soda}/adjust", ['type' => 'entry', 'quantity' => 10, 'reason' => 'Compra'])->assertOk()->assertJsonPath('data.quantity', 10)->assertJsonPath('data.sold_out', false);
    stockApi($this, 'POST', "stock/{$this->soda}/adjust", ['type' => 'loss', 'quantity' => 11])->assertStatus(422);
    stockApi($this, 'POST', "stock/{$this->soda}/adjust", ['type' => 'loss', 'quantity' => 2, 'reason' => 'Vencido'])->assertOk()->assertJsonPath('data.quantity', 8);
    stockApi($this, 'POST', "stock/{$this->soda}/adjust", ['type' => 'count', 'quantity' => 4])->assertOk()->assertJsonPath('data.quantity', 4);

    // Atendente pausou à mão; zerar e repor não reativa.
    withSession($this->session)->putJson(tenantUrl($this->a, "merchant/branches/{$this->branch}/availability/{$this->soda}"), ['available' => false])->assertOk();
    stockApi($this, 'POST', "stock/{$this->soda}/adjust", ['type' => 'count', 'quantity' => 0])->assertOk();
    stockApi($this, 'POST', "stock/{$this->soda}/adjust", ['type' => 'entry', 'quantity' => 3])->assertOk();
    expect(sodaRow($this)['sold_out'])->toBeTrue();

    // Desligar o controle apaga o saldo.
    stockApi($this, 'PUT', "stock/{$this->soda}", ['tracked' => false])->assertOk();
    expect(sodaRow($this)['tracked'])->toBeFalse()->and(inTenant($this->a, fn () => ProductStock::query()->count()))->toBe(0);
});

it('atendente consulta mas não ajusta; outro restaurante não vê', function () {
    stockApi($this, 'PUT', "stock/{$this->soda}", ['tracked' => true, 'quantity' => 5])->assertOk();
    $attendant = merchantLogin($this->a, makeMember($this->a, role: Role::ATTENDANT, branch: $this->fa->branch));
    stockApi($this, 'GET', 'stock', session: $attendant)->assertOk();
    stockApi($this, 'POST', "stock/{$this->soda}/adjust", ['type' => 'entry', 'quantity' => 1], $attendant)->assertForbidden();

    $ownerB = merchantLogin($this->b, makeMember($this->b));
    withSession($ownerB)->postJson(tenantUrl($this->b, "merchant/stock/{$this->soda}/adjust"), ['type' => 'entry', 'quantity' => 1])->assertNotFound();
});
