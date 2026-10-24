<?php

use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use Tests\Support\CatalogFixture;
use Tests\Support\Shop;

beforeEach(function () {
    $this->a = makeTenant('tenant-a');
    $this->b = makeTenant('tenant-b');
    $this->fa = CatalogFixture::create($this->a);
    $this->fb = CatalogFixture::create($this->b);
    $this->session = merchantLogin($this->a, makeMember($this->a));
    $this->url = tenantUrl($this->a, 'merchant/branches/'.$this->fa->branch->public_id.'/delivery');
});

function zonesPayload(array $zones = []): array
{
    return [
        'pickup_enabled' => true, 'delivery_enabled' => true, 'pickup_eta_minutes' => 25,
        'zones' => $zones ?: [
            ['name' => 'Orla', 'neighborhoods' => ['Pajuçara', 'Ponta Verde'], 'fee' => '6.00', 'minimum_order' => '25.00', 'eta_minutes' => 40],
            ['name' => 'Centro', 'neighborhoods' => ['Centro'], 'fee' => '9.00', 'eta_minutes' => 60, 'active' => false],
        ],
    ];
}

it('salva retirada/entrega e zonas; cardápio público mostra só zonas ativas', function () {
    withSession($this->session)->putJson($this->url, zonesPayload())->assertOk()
        ->assertJsonCount(2, 'data.zones')->assertJsonPath('data.pickup_eta_minutes', 25);

    $this->getJson(tenantUrl($this->a, 'storefront'))
        ->assertJsonPath('data.branches.0.fulfillment.delivery_enabled', true)
        ->assertJsonCount(1, 'data.branches.0.fulfillment.zones')
        ->assertJsonPath('data.branches.0.fulfillment.zones.0.neighborhoods', ['Pajuçara', 'Ponta Verde']);
});

it('atualiza zona existente por ID e remove as que saíram', function () {
    $first = withSession($this->session)->putJson($this->url, zonesPayload())->json('data.zones.0.id');

    withSession($this->session)->putJson($this->url, zonesPayload([
        ['id' => $first, 'name' => 'Orla Norte', 'neighborhoods' => ['Jatiúca'], 'fee' => '5.00', 'eta_minutes' => 35],
    ]))->assertOk()->assertJsonCount(1, 'data.zones')->assertJsonPath('data.zones.0.id', $first);
});

it('ISOLAMENTO: zona de outro tenant ou unidade de outro tenant não é aceita', function () {
    $zoneB = Shop::enableDelivery($this->fb);

    withSession($this->session)->putJson($this->url, zonesPayload([
        ['id' => $zoneB->public_id, 'name' => 'X', 'neighborhoods' => ['X'], 'fee' => '1.00', 'eta_minutes' => 30],
    ]))->assertStatus(422);

    withSession($this->session)->putJson(tenantUrl($this->a, 'merchant/branches/'.$this->fb->branch->public_id.'/delivery'), zonesPayload())
        ->assertNotFound();
});

it('atendente vê mas não altera a entrega', function () {
    $attendant = makeMember($this->a, User::factory()->create(), Role::ATTENDANT, $this->fa->branch);
    $s = merchantLogin($this->a, $attendant);

    withSession($s)->getJson($this->url)->assertOk();
    withSession($s)->putJson($this->url, zonesPayload())->assertForbidden();
});
