<?php

use App\Modules\Ordering\Models\Coupon;
use App\Modules\Ordering\Models\CouponRedemption;
use App\Modules\Ordering\Models\Order;
use Tests\Support\CatalogFixture;
use Tests\Support\FakeMercadoPago;
use Tests\Support\Marketing;
use Tests\Support\Shop;

beforeEach(function () {
    $this->a = makeTenant('tenant-a');
    $this->fa = CatalogFixture::create($this->a);
    $this->zone = Shop::enableDelivery($this->fa);
    Marketing::withCoupons($this->a);
    $this->owner = makeMember($this->a);
    $this->session = merchantLogin($this->a, $this->owner);
});

function coupon(object $t, array $body)
{
    return withSession($t->session)->postJson(tenantUrl($t->a, 'merchant/coupons'), $body);
}

function couponCart(object $t, int $pizzas = 1): string
{
    $token = Shop::newCart($t->a);
    Shop::addPizza($t->fa, $token, $pizzas);

    return $token;
}

it('cupom percentual com teto: prévia no carrinho e desconto no pedido, com linha nos totais', function () {
    coupon($this, ['code' => 'pizza10', 'discount_type' => 'percent', 'percent' => '10', 'max_discount' => '6,00'])->assertCreated()
        ->assertJsonPath('data.code', 'PIZZA10');

    $token = couponCart($this, 2); // 80,00 → 10% = 8,00, teto 6,00
    $this->postJson(tenantUrl($this->a, "carts/{$token}/coupon"), ['code' => ' pizza10 '])->assertOk()
        ->assertJsonPath('data.discount', '6.00');

    $order = Shop::checkout($this->a, Shop::pickupPayload($token, ['coupon' => 'Pizza10']))->assertCreated()
        ->assertJsonPath('data.total', '74.00')->assertJsonPath('data.discount_total', '6.00')->json('data.order_id');

    $model = inTenant($this->a, fn () => Order::query()->where('public_id', $order)->with('totals')->sole());
    expect($model->totals->pluck('amount', 'code')->all())->toBe(['subtotal' => '80.00', 'discount' => '6.00', 'total' => '74.00'])
        ->and($model->totals->firstWhere('code', 'discount')->label)->toBe('Cupom PIZZA10')
        ->and(inTenant($this->a, fn () => Coupon::query()->sole()->times_used))->toBe(1);
});

it('entrega grátis zera só a taxa; valor fixo não passa do subtotal', function () {
    coupon($this, ['code' => 'FRETE', 'discount_type' => 'free_delivery'])->assertCreated();
    coupon($this, ['code' => 'MENOS100', 'discount_type' => 'fixed', 'amount' => '100'])->assertCreated();

    $token = couponCart($this);
    Shop::checkout($this->a, Shop::deliveryPayload($token, $this->zone, ['coupon' => 'FRETE']))->assertCreated()
        ->assertJsonPath('data.total', '40.00'); // 40 + 7 de entrega − 7

    $token = couponCart($this);
    Shop::checkout($this->a, Shop::deliveryPayload($token, $this->zone, ['coupon' => 'MENOS100']))->assertCreated()
        ->assertJsonPath('data.total', '7.00'); // desconto limitado aos 40 do subtotal
});

it('regras: pedido mínimo, período, limite total, limite por cliente e primeiro pedido', function () {
    coupon($this, ['code' => 'MINIMO', 'discount_type' => 'fixed', 'amount' => '5', 'min_subtotal' => '50'])->assertCreated();
    coupon($this, ['code' => 'FUTURO', 'discount_type' => 'fixed', 'amount' => '5', 'starts_at' => now('America/Sao_Paulo')->addDay()->format('Y-m-d\TH:i')])->assertCreated();
    coupon($this, ['code' => 'UMAVEZ', 'discount_type' => 'fixed', 'amount' => '5', 'usage_limit' => 1])->assertCreated();
    coupon($this, ['code' => 'PORCLIENTE', 'discount_type' => 'fixed', 'amount' => '5', 'per_customer_limit' => 1])->assertCreated();
    coupon($this, ['code' => 'BEMVINDO', 'discount_type' => 'fixed', 'amount' => '5', 'first_order_only' => true])->assertCreated();

    $fail = fn (string $code, string $err, string $phone = '(82) 99876-5432') => Shop::checkout($this->a, Shop::pickupPayload(couponCart($this), ['coupon' => $code, 'customer' => ['phone' => $phone]]))
        ->assertStatus(422)->assertJsonPath('code', $err);
    $ok = fn (string $code, string $phone = '(82) 99876-5432') => Shop::checkout($this->a, Shop::pickupPayload(couponCart($this), ['coupon' => $code, 'customer' => ['phone' => $phone]]))->assertCreated();

    $fail('MINIMO', 'coupon_min_subtotal');
    $fail('FUTURO', 'coupon_invalid');
    $fail('NAOEXISTE', 'coupon_invalid');

    $ok('UMAVEZ');
    $fail('UMAVEZ', 'coupon_exhausted', '(82) 98888-1111');

    $ok('PORCLIENTE');
    $fail('PORCLIENTE', 'coupon_customer_limit');
    $ok('PORCLIENTE', '(82) 98888-1111');

    $fail('BEMVINDO', 'coupon_first_order_only'); // este celular já pediu
    $ok('BEMVINDO', '(82) 97777-2222');
});

it('pedido cancelado devolve o uso do cupom', function () {
    coupon($this, ['code' => 'UMAVEZ', 'discount_type' => 'fixed', 'amount' => '5', 'usage_limit' => 1])->assertCreated();
    $order = Shop::checkout($this->a, Shop::pickupPayload(couponCart($this), ['coupon' => 'UMAVEZ']))->assertCreated()->json('data.order_id');

    withSession($this->session)->patchJson(tenantUrl($this->a, "merchant/orders/{$order}/status"), ['status' => 'rejected', 'reason' => 'out_of_stock'])->assertOk();

    expect(inTenant($this->a, fn () => Coupon::query()->sole()->times_used))->toBe(0)
        ->and(inTenant($this->a, fn () => CouponRedemption::query()->sole()->status))->toBe('voided');
    Shop::checkout($this->a, Shop::pickupPayload(couponCart($this), ['coupon' => 'UMAVEZ']))->assertCreated();
});

it('Pix cobra o total com desconto', function () {
    $mp = FakeMercadoPago::install();
    $mp->connect($this->a);
    coupon($this, ['code' => 'PIX5', 'discount_type' => 'fixed', 'amount' => '5'])->assertCreated();

    Shop::checkout($this->a, Shop::pickupPayload(couponCart($this), [
        'coupon' => 'PIX5', 'customer' => ['email' => 'a@b.test'], 'payment' => ['method' => 'pix'],
    ]))->assertCreated()->assertJsonPath('data.total', '35.00');
    expect($mp->requestsTo('/v1/payments')[0]['body']['transaction_amount'])->toEqual(35.0);
});

it('plano sem cupons: checkout recusa e o painel não mostra a tela; código duplicado e cupom usado não apagável', function () {
    coupon($this, ['code' => 'DUP', 'discount_type' => 'fixed', 'amount' => '5'])->assertCreated();
    coupon($this, ['code' => 'dup', 'discount_type' => 'fixed', 'amount' => '5'])->assertStatus(422);
    Shop::checkout($this->a, Shop::pickupPayload(couponCart($this), ['coupon' => 'DUP']))->assertCreated();
    $id = inTenant($this->a, fn () => Coupon::query()->sole()->public_id);
    withSession($this->session)->deleteJson(tenantUrl($this->a, "merchant/coupons/{$id}"))->assertStatus(409);

    $b = makeTenant('tenant-b');
    $fb = CatalogFixture::create($b);
    // Cupom existente num tenant cujo plano não inclui cupons (ex.: plano rebaixado).
    inTenant($b, fn () => Coupon::query()->create(['code' => 'DUP', 'discount_type' => 'fixed', 'amount' => '5.00', 'active' => true]));
    $token = Shop::newCart($b);
    Shop::addPizza($fb, $token);
    Shop::checkout($b, Shop::pickupPayload($token, ['coupon' => 'DUP']))->assertStatus(422)->assertJsonPath('code', 'coupon_invalid');
    $sessionB = merchantLogin($b, makeMember($b));
    withSession($sessionB)->getJson(tenantUrl($b, 'merchant/coupons'))->assertForbidden();
});
