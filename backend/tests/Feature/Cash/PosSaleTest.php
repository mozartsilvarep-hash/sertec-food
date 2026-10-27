<?php

use App\Modules\Cash\Models\CashEntry;
use App\Modules\Identity\Models\Role;
use App\Modules\Ordering\Models\Order;
use Illuminate\Support\Str;
use Tests\Support\CatalogFixture;

beforeEach(function () {
    $this->a = makeTenant('tenant-a');
    $this->b = makeTenant('tenant-b');
    $this->fa = CatalogFixture::create($this->a);
    $this->fb = CatalogFixture::create($this->b);
    withCashbook($this->a);
    withCashbook($this->b);
    $this->owner = makeMember($this->a);
    $this->session = merchantLogin($this->a, $this->owner);
    $this->branch = $this->fa->branch->public_id;
});

function posSale(object $t, array $body, ?string $key = null, ?string $session = null)
{
    return withSession($session ?? $t->session)->withHeader('Idempotency-Key', $key ?? (string) Str::uuid())
        ->postJson(tenantUrl($t->a, 'merchant/cash/pos/sales'), ['branch' => $t->branch] + $body);
}

function posPizza(object $t, int $qty = 1): array
{
    return ['product_id' => $t->fa->pizza->public_id, 'variant_id' => $t->fa->medium->public_id, 'options' => $t->fa->pick(['Calabresa' => 1]), 'quantity' => $qty];
}

function posOpenCash(object $t): void
{
    withSession($t->session)->postJson(tenantUrl($t->a, 'merchant/cash/session'), ['branch' => $t->branch, 'opening_amount' => '50,00'])->assertCreated();
}

it('exige caixa aberto', function () {
    posSale($this, ['items' => [posPizza($this)], 'payments' => [['method' => 'cash', 'amount' => '40,00']]])
        ->assertStatus(409)->assertJsonPath('code', 'cash_closed');
});

it('venda completa: sabor pelo preço do servidor, avulso, desconto %, taxa, pagamento dividido com troco', function () {
    posOpenCash($this);
    $quote = posSale($this, ['items' => [posPizza($this), ['description' => 'Vela de aniversário', 'unit_price' => '5,00', 'quantity' => 2]], 'discount' => ['type' => 'percent', 'value' => '10'], 'service_percent' => 10, 'payments' => [['method' => 'cash', 'amount' => '1,00']]])
        ->assertStatus(422)->assertJsonPath('code', 'sale_payment_mismatch');
    $total = $quote->json('details.total');
    // 40 (pizza) + 10 (avulso) = 50; −10% = 45; +10% serviço = 49,50
    expect($total)->toBe('49.50');

    $res = posSale($this, [
        'items' => [posPizza($this), ['description' => 'Vela de aniversário', 'unit_price' => '5,00', 'quantity' => 2]],
        'discount' => ['type' => 'percent', 'value' => '10'],
        'service_percent' => 10,
        'payments' => [['method' => 'pix', 'amount' => '29,50'], ['method' => 'cash', 'amount' => '20,00']],
        'cash_received' => '50,00',
    ])->assertCreated()->assertJsonPath('data.total', '49.50')->assertJsonPath('data.change', '30.00')->assertJsonPath('data.status', 'completed');

    $order = inTenant($this->a, fn () => Order::query()->where('public_id', $res->json('data.order_id'))->with('totals', 'items', 'posPayments')->sole());
    expect($order->channel)->toBe('pos')->and($order->fulfillment_type->value)->toBe('counter')
        ->and($order->totals->pluck('amount', 'code')->all())->toMatchArray(['subtotal' => '50.00', 'discount' => '5.00', 'service' => '4.50', 'total' => '49.50'])
        ->and($order->items->pluck('product_name_snapshot')->all())->toContain('Vela de aniversário')
        ->and($order->posPayments->pluck('amount', 'method.value')->all())->toBe(['pix' => '29.50', 'cash' => '20.00']);

    // Caixa: uma entrada por forma; gaveta = 50 + 20.
    $entries = inTenant($this->a, fn () => CashEntry::query()->where('order_id', $order->id)->get());
    expect($entries)->toHaveCount(2);
    withSession($this->session)->getJson(tenantUrl($this->a, "merchant/cash/session?branch={$this->branch}"))->assertJsonPath('data.expected_amount', '70.00');

    // Relatório: formas do balcão aparecem separadas.
    $methods = collect(withSession($this->session)->getJson(tenantUrl($this->a, 'merchant/reports/summary'))->assertOk()->json('data.by_payment_method'))->pluck('total', 'label');
    expect($methods->all())->toMatchArray(['Balcão · Pix' => '29.50', 'Balcão · Dinheiro' => '20.00']);
});

it('clique duplo não vende duas vezes; cozinha recebe; cancelar devolve no caixa', function () {
    posOpenCash($this);
    $key = (string) Str::uuid();
    $body = ['items' => [posPizza($this)], 'payments' => [['method' => 'debit', 'amount' => '40,00']], 'send_to_kitchen' => true];
    $first = posSale($this, $body, $key)->assertCreated()->assertJsonPath('data.status', 'accepted');
    posSale($this, $body, $key)->assertCreated()->assertJsonPath('data.order_id', $first->json('data.order_id'));
    expect(inTenant($this->a, fn () => Order::query()->count()))->toBe(1);

    $id = $first->json('data.order_id');
    withSession($this->session)->getJson(tenantUrl($this->a, "merchant/orders/{$id}"))->assertOk()
        ->assertJsonPath('data.channel', 'pos')->assertJsonPath('data.pos_payments.0.label', 'Cartão de débito')
        ->assertJsonPath('data.seller', $this->owner->name);

    withSession($this->session)->patchJson(tenantUrl($this->a, "merchant/orders/{$id}/status"), ['status' => 'cancelled', 'reason' => 'customer_request'])->assertOk();
    $out = inTenant($this->a, fn () => CashEntry::query()->where('type', CashEntry::OUT)->sole());
    expect($out->amount)->toBe('40.00')->and($out->method->value)->toBe('debit');
});

it('cliente: fidelidade ganha selo na venda concluída; celular inválido é recusado', function () {
    withSession($this->session)->putJson(tenantUrl($this->a, 'merchant/loyalty'), ['active' => true, 'stamps_required' => 2, 'reward_type' => 'fixed', 'reward_amount' => '5,00'])->assertOk();
    posOpenCash($this);
    posSale($this, ['items' => [posPizza($this)], 'customer' => ['phone' => '123'], 'payments' => [['method' => 'cash', 'amount' => '40,00']]])->assertStatus(422);
    foreach (range(1, 2) as $i) {
        posSale($this, ['items' => [posPizza($this)], 'customer' => ['name' => 'Ana', 'phone' => '(79) 99999-1111'], 'payments' => [['method' => 'cash', 'amount' => '40,00']]])->assertCreated();
    }
    withSession($this->session)->getJson(tenantUrl($this->a, 'merchant/cash/pos/customer?phone=79999991111'))->assertOk()
        ->assertJsonPath('data.name', 'Ana')->assertJsonPath('data.loyalty.rewards', 1);
    posSale($this, ['items' => [posPizza($this)], 'customer' => ['phone' => '79999991111'], 'use_loyalty' => true, 'payments' => [['method' => 'cash', 'amount' => '35,00']]])
        ->assertCreated()->assertJsonPath('data.total', '35.00');
});

it('catálogo do PDV traz código, favorito e mais vendidos; isolamento entre restaurantes', function () {
    withSession($this->session)->putJson(tenantUrl($this->a, 'merchant/products/'.$this->fa->soda->public_id), [
        'name' => 'Refrigerante lata', 'code' => 'coca', 'pos_favorite' => true, 'variants' => [['id' => $this->fa->sodaVariant->public_id, 'name' => 'Lata', 'price' => '6.50']],
    ])->assertOk()->assertJsonPath('data.code', 'COCA');
    $cat = withSession($this->session)->getJson(tenantUrl($this->a, "merchant/cash/pos/catalog?branch={$this->branch}"))->assertOk();
    expect($cat->json('data.products.'.$this->fa->soda->public_id))->toBe(['code' => 'COCA', 'favorite' => true, 'quick_variant' => $this->fa->sodaVariant->public_id])
        ->and(collect($cat->json('data.methods'))->pluck('method')->all())->toBe(['cash', 'pix', 'debit', 'credit', 'other']);

    // Produto do restaurante B não entra na venda do A.
    posOpenCash($this);
    posSale($this, ['items' => [['product_id' => $this->fb->soda->public_id, 'variant_id' => $this->fb->sodaVariant->public_id, 'quantity' => 1]], 'payments' => [['method' => 'cash', 'amount' => '6,50']]])
        ->assertStatus(422);

    // Cozinha não opera o PDV.
    $kitchen = merchantLogin($this->a, makeMember($this->a, role: Role::KITCHEN, branch: $this->fa->branch));
    posSale($this, ['items' => [posPizza($this)], 'payments' => [['method' => 'cash', 'amount' => '40,00']]], session: $kitchen)->assertForbidden();
});
