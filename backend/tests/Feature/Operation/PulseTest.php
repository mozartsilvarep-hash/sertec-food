<?php

use App\Modules\Identity\Models\Role;
use App\Modules\Ordering\Models\Order;
use Tests\Support\CatalogFixture;
use Tests\Support\FakeMercadoPago;
use Tests\Support\Pay;
use Tests\Support\Shop;

beforeEach(function () {
    $this->a = makeTenant('tenant-a');
    $this->b = makeTenant('tenant-b');
    $this->fa = CatalogFixture::create($this->a);
    $this->fb = CatalogFixture::create($this->b);
    $this->session = merchantLogin($this->a, makeMember($this->a));
});

function pulse(object $t, ?string $session = null)
{
    return withSession($session ?? $t->session)->getJson(tenantUrl($t->a, 'merchant/orders/pulse'))->assertOk()->json('data');
}

function pickupOrder(CatalogFixture $f): string
{
    $token = Shop::newCart($f->tenant);
    Shop::addPizza($f, $token);

    return (string) Shop::checkout($f->tenant, Shop::pickupPayload($token))->assertCreated()->json('data.order_id');
}

it('pulso muda quando chega pedido e quando o status muda; conta os novos', function () {
    $start = pulse($this);
    expect($start['pending'])->toBe(0)->and($start['oldest_pending_at'])->toBeNull();

    $order = pickupOrder($this->fa);
    $afterNew = pulse($this);
    expect($afterNew['version'])->not->toBe($start['version'])
        ->and($afterNew['pending'])->toBe(1)
        ->and($afterNew['oldest_pending_at'])->not->toBeNull();

    expect(pulse($this)['version'])->toBe($afterNew['version']); // estável sem mudanças

    withSession($this->session)->patchJson(tenantUrl($this->a, "merchant/orders/{$order}/status"), ['status' => 'accepted'])->assertOk();
    $afterAccept = pulse($this);
    expect($afterAccept['version'])->not->toBe($afterNew['version'])->and($afterAccept['pending'])->toBe(0);
});

it('isolamento: pedido do tenant B não mexe no pulso do A', function () {
    $before = pulse($this);
    pickupOrder($this->fb);

    expect(pulse($this))->toBe($before);
});

it('funcionário de uma unidade só sente as mudanças da própria unidade', function () {
    $other = makeBranch($this->a, ['name' => 'Outra', 'pickup_enabled' => true]);
    $session = merchantLogin($this->a, makeMember($this->a, role: Role::ATTENDANT, branch: $this->fa->branch));
    $before = pulse($this, $session);

    $token = (string) $this->postJson(tenantUrl($this->a, 'carts'), ['branch_id' => $other->public_id])->assertCreated()->json('data.token');
    Shop::addPizza($this->fa, $token);
    Shop::checkout($this->a, Shop::pickupPayload($token))->assertCreated();

    expect(pulse($this, $session))->toBe($before)
        ->and(inTenant($this->a, fn () => Order::query()->where('branch_id', $other->id)->count()))->toBe(1);
});

it('pagamento online pendente não conta como novo; confirmado conta', function () {
    $mp = FakeMercadoPago::install();
    $mp->connect($this->a);
    $placed = Pay::order($this->fa, 'pix');
    expect(pulse($this)['pending'])->toBe(0);

    $mp->set($mp->lastPaymentId(), 'approved');
    Pay::webhook($this->a, $mp->lastPaymentId())->assertOk();
    expect(pulse($this)['pending'])->toBe(1);
});
