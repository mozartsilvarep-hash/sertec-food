<?php

use App\Modules\Identity\Models\Role;
use App\Modules\Ordering\Models\Customer;
use App\Modules\Ordering\Models\Order;
use App\Modules\Platform\Models\AuditLog;
use Tests\Support\CatalogFixture;
use Tests\Support\Shop;

beforeEach(function () {
    $this->a = makeTenant('tenant-a');
    $this->b = makeTenant('tenant-b');
    $this->fa = CatalogFixture::create($this->a);
    $this->fb = CatalogFixture::create($this->b);
    $this->session = merchantLogin($this->a, makeMember($this->a));
});

function lgpdOrder(CatalogFixture $f, string $phone, string $status = 'completed'): string
{
    $token = Shop::newCart($f->tenant);
    Shop::addPizza($f, $token);
    $id = (string) Shop::checkout($f->tenant, Shop::pickupPayload($token, [
        'customer' => ['name' => 'Maria Souza', 'phone' => $phone, 'email' => 'maria@x.test'],
        'notes' => 'Interfone 302, falar com Maria',
    ]))->assertCreated()->json('data.order_id');
    inTenant($f->tenant, fn () => Order::query()->where('public_id', $id)->update(['status' => $status]));

    return $id;
}

it('relatório e exclusão a pedido do titular, só no próprio estabelecimento', function () {
    $o1 = lgpdOrder($this->fa, '(82) 99876-5432');
    lgpdOrder($this->fb, '(82) 99876-5432'); // mesmo cliente no tenant B

    withSession($this->session)->getJson(tenantUrl($this->a, 'merchant/privacy/customer?phone=82998765432'))->assertOk()
        ->assertJsonPath('data.customer.email', 'maria@x.test')
        ->assertJsonCount(1, 'data.orders');

    withSession($this->session)->postJson(tenantUrl($this->a, 'merchant/privacy/customer/erase'), ['phone' => '(82) 99876-5432'])
        ->assertOk()->assertJsonPath('data.orders_anonymized', 1);

    $order = inTenant($this->a, fn () => Order::query()->where('public_id', $o1)->sole());
    expect($order->customer_name)->toBe('Cliente (dados removidos)')
        ->and($order->customer_phone)->toBe('')
        ->and($order->notes)->toBeNull()
        ->and($order->customer_id)->toBeNull()
        ->and($order->anonymized_at)->not->toBeNull()
        ->and($order->total)->toBe('40.00')
        ->and(inTenant($this->a, fn () => Customer::query()->count()))->toBe(0)
        // Tenant B intacto
        ->and(inTenant($this->b, fn () => Customer::query()->where('phone', '82998765432')->count()))->toBe(1)
        ->and(inTenant($this->b, fn () => Order::query()->whereNull('anonymized_at')->count()))->toBe(1);

    // Trilha sem telefone em claro
    $log = AuditLog::query()->where('action', 'privacy.customer_erased')->sole();
    expect(json_encode($log->metadata_json))->not->toContain('99876');

    // O pedido anonimizado continua aparecendo para o restaurante sem quebrar.
    withSession($this->session)->getJson(tenantUrl($this->a, "merchant/orders/{$o1}"))->assertOk()
        ->assertJsonPath('data.customer.name', 'Cliente (dados removidos)')->assertJsonPath('data.customer.phone', '');
});

it('não remove enquanto houver pedido em andamento; atendente não tem acesso', function () {
    lgpdOrder($this->fa, '(82) 99876-5432', 'preparing');
    withSession($this->session)->postJson(tenantUrl($this->a, 'merchant/privacy/customer/erase'), ['phone' => '82998765432'])
        ->assertStatus(409)->assertJsonPath('code', 'order_in_progress');

    $att = merchantLogin($this->a, makeMember($this->a, role: Role::ATTENDANT));
    withSession($att)->getJson(tenantUrl($this->a, 'merchant/privacy/customer?phone=82998765432'))->assertForbidden();
});

it('retenção: anonimiza só pedidos finalizados mais antigos que o prazo', function () {
    $old = lgpdOrder($this->fa, '(82) 99876-5432');
    $recent = lgpdOrder($this->fa, '(82) 98888-1111');
    $oldActive = lgpdOrder($this->fa, '(82) 97777-2222', 'preparing');
    inTenant($this->a, fn () => Order::query()->whereIn('public_id', [$old, $oldActive])->update(['placed_at' => now()->subMonths(30)]));
    inTenant($this->a, fn () => Customer::query()->where('phone', '82998765432')->update(['last_order_at' => now()->subMonths(30)]));

    $this->artisan('privacy:anonymize')->assertSuccessful();

    $byId = fn (string $id) => inTenant($this->a, fn () => Order::query()->where('public_id', $id)->sole());
    expect($byId($old)->anonymized_at)->not->toBeNull()
        ->and($byId($recent)->anonymized_at)->toBeNull()
        ->and($byId($oldActive)->anonymized_at)->toBeNull()
        ->and(inTenant($this->a, fn () => Customer::query()->pluck('phone')->sort()->values()->all()))->toBe(['82977772222', '82988881111']);

    $this->artisan('privacy:anonymize', ['--months' => 2])->assertFailed();
});
