<?php

use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\TenantUser;
use App\Modules\Identity\Models\User;
use App\Modules\Ordering\Models\Order;
use App\Modules\Ordering\Models\OrderStatusHistory;
use App\Modules\Tenancy\Models\Branch;
use Tests\Support\CatalogFixture;
use Tests\Support\Shop;

beforeEach(function () {
    $this->a = makeTenant('tenant-a');
    $this->b = makeTenant('tenant-b');
    $this->fa = CatalogFixture::create($this->a);
    $this->fb = CatalogFixture::create($this->b);
    $this->zone = Shop::enableDelivery($this->fa);
    $this->owner = makeMember($this->a);
    $this->session = merchantLogin($this->a, $this->owner);
});

function placeOrder(object $t, string $type = 'pickup', ?string $branchId = null): string
{
    $token = (string) test()->postJson(tenantUrl($t->a, 'carts'), ['branch_id' => $branchId])->json('data.token');
    Shop::addPizza($t->fa, $token, 1, ['Calabresa' => 1]);
    $payload = $type === 'delivery' ? Shop::deliveryPayload($token, $t->zone) : Shop::pickupPayload($token);

    return (string) Shop::checkout($t->a, $payload)->assertCreated()->json('data.order_id');
}

function move(object $t, string $order, string $status, array $extra = [], ?string $session = null)
{
    return withSession($session ?? $t->session)->patchJson(tenantUrl($t->a, "merchant/orders/{$order}/status"), ['status' => $status, ...$extra]);
}

it('entrega: fluxo completo até concluído marca o pagamento na entrega como pago', function () {
    $order = placeOrder($this, 'delivery');

    move($this, $order, 'accepted', ['eta_minutes' => 40])->assertOk()->assertJsonPath('data.eta_minutes', 40);
    move($this, $order, 'preparing')->assertOk();
    move($this, $order, 'ready')->assertOk()->assertJsonPath('data.next_statuses.0.status', 'out_for_delivery');
    move($this, $order, 'out_for_delivery')->assertOk();
    move($this, $order, 'completed')->assertOk()
        ->assertJsonPath('data.payment_status', 'paid')
        ->assertJsonPath('data.next_statuses', [])
        ->assertJsonCount(6, 'data.history');

    $history = inTenant($this->a, fn () => OrderStatusHistory::query()->whereNotNull('actor_user_id')->pluck('actor_user_id')->unique()->all());
    expect($history)->toBe([$this->owner->id]);
});

it('retirada pula "saiu para entrega"', function () {
    $order = placeOrder($this);
    foreach (['accepted', 'preparing', 'ready'] as $s) {
        move($this, $order, $s)->assertOk();
    }

    move($this, $order, 'out_for_delivery')->assertStatus(409)->assertJsonPath('code', 'invalid_transition')
        ->assertJsonPath('details.allowed', ['completed', 'cancelled']);
    move($this, $order, 'completed')->assertOk();
});

it('transições proibidas devolvem 409 e não mudam nada', function () {
    $order = placeOrder($this);

    move($this, $order, 'ready')->assertStatus(409)->assertJsonPath('details.from', 'pending_acceptance');
    move($this, $order, 'pending_acceptance')->assertStatus(409);

    expect(inTenant($this->a, fn () => Order::query()->sole()->status->value))->toBe('pending_acceptance');
});

it('recusa exige motivo, anula o pagamento pendente e encerra o pedido', function () {
    $order = placeOrder($this);

    move($this, $order, 'rejected')->assertStatus(422)->assertJsonPath('code', 'reason_required');
    move($this, $order, 'rejected', ['reason' => 'out_of_stock', 'note' => 'Acabou a calabresa'])->assertOk()
        ->assertJsonPath('data.payment_status', 'voided')
        ->assertJsonPath('data.cancellation_reason', 'out_of_stock');

    move($this, $order, 'accepted')->assertStatus(409);
});

it('lista ativos e finalizados separadamente, com paginação por cursor', function () {
    $first = placeOrder($this);
    placeOrder($this);
    move($this, $first, 'rejected', ['reason' => 'store_closed']);

    $s = withSession($this->session);
    expect($s->getJson(tenantUrl($this->a, 'merchant/orders'))->json('data'))->toHaveCount(1);
    expect($s->getJson(tenantUrl($this->a, 'merchant/orders?view=finished'))->json('data.0.id'))->toBe($first);
    $page = $s->getJson(tenantUrl($this->a, 'merchant/orders?view=all&per_page=1'));
    expect($page->json('meta.next_cursor'))->not->toBeNull();
});

it('ISOLAMENTO: pedidos de B não aparecem nem podem ser alterados em A', function () {
    $tb = Shop::newCart($this->b);
    Shop::addSoda($this->fb, $tb);
    $orderB = Shop::checkout($this->b, Shop::pickupPayload($tb))->json('data.order_id');
    placeOrder($this);

    $s = withSession($this->session);
    expect(collect($s->getJson(tenantUrl($this->a, 'merchant/orders'))->json('data'))->pluck('id'))->not->toContain($orderB);
    $s->getJson(tenantUrl($this->a, "merchant/orders/{$orderB}"))->assertNotFound();
    $s->patchJson(tenantUrl($this->a, "merchant/orders/{$orderB}/status"), ['status' => 'accepted'])->assertNotFound();

    expect(inTenant($this->b, fn () => Order::query()->sole()->status->value))->toBe('pending_acceptance');
});

it('ISOLAMENTO por unidade: atendente só vê e opera pedidos da sua unidade', function () {
    $other = inTenant($this->a, fn () => Branch::query()->create(['name' => 'Jatiúca']));
    $mine = placeOrder($this);
    $theirs = placeOrder($this, 'pickup', $other->public_id);

    $attendant = makeMember($this->a, User::factory()->create(), Role::ATTENDANT, $this->fa->branch);
    $s = merchantLogin($this->a, $attendant);

    $ids = collect(withSession($s)->getJson(tenantUrl($this->a, 'merchant/orders'))->json('data'))->pluck('id')->all();
    expect($ids)->toBe([$mine]);
    withSession($s)->getJson(tenantUrl($this->a, "merchant/orders/{$theirs}"))->assertNotFound();
    move($this, $theirs, 'accepted', [], $s)->assertNotFound();
    move($this, $mine, 'accepted', [], $s)->assertOk();
});

it('cozinha opera pedidos; usuário sem permissão de pedidos recebe 403', function () {
    $order = placeOrder($this);
    $kitchen = makeMember($this->a, User::factory()->create(), Role::KITCHEN);
    move($this, $order, 'accepted', [], merchantLogin($this->a, $kitchen))->assertOk();

    $noOrders = Role::factory()->create(['tenant_id' => $this->a->id, 'key' => 'so-cardapio']);
    $catalogOnly = User::factory()->create();
    asPlatform(fn () => TenantUser::factory()->create([
        'tenant_id' => $this->a->id, 'user_id' => $catalogOnly->id, 'role_id' => $noOrders->id,
    ]));
    withSession(merchantLogin($this->a, $catalogOnly))->getJson(tenantUrl($this->a, 'merchant/orders'))->assertForbidden();
});

it('mostra ao restaurante telefone e endereço completos', function () {
    $order = placeOrder($this, 'delivery');

    withSession($this->session)->getJson(tenantUrl($this->a, "merchant/orders/{$order}"))->assertOk()
        ->assertJsonPath('data.customer.phone', '(82) 99876-5432')
        ->assertJsonPath('data.address.street', 'Av. Álvaro Otacílio')
        ->assertJsonPath('data.payment_method_label', 'Cartão na entrega');
});
