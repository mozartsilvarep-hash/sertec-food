<?php

use App\Modules\Identity\Models\Role;
use App\Modules\Platform\Models\AuditLog;
use Tests\Support\CatalogFixture;

beforeEach(function () {
    $this->a = makeTenant('tenant-a');
    $this->b = makeTenant('tenant-b');
    $this->fa = CatalogFixture::create($this->a);
    $this->owner = makeMember($this->a);
    $this->session = merchantLogin($this->a, $this->owner);
});

it('escritas do painel são auditadas automaticamente, sem o corpo da requisição', function () {
    withSession($this->session)->putJson(tenantUrl($this->a, "merchant/branches/{$this->fa->branch->public_id}/availability/{$this->fa->soda->public_id}"), ['available' => false])->assertOk();
    withSession($this->session)->postJson(tenantUrl($this->a, 'merchant/categories'), [
        'menu_id' => $this->fa->menu->public_id, 'name' => 'Combos secretos',
    ])->assertCreated();

    $logs = inTenant($this->a, fn () => AuditLog::query()->where('tenant_id', $this->a->id)->orderBy('id')->get());
    $availability = $logs->firstWhere('action', 'availability.update');
    $category = $logs->firstWhere('action', 'categories.store');

    expect($availability)->not->toBeNull()
        ->and($availability->actor_user_id)->toBe($this->owner->id)
        ->and($availability->metadata_json)->toMatchArray(['branch' => $this->fa->branch->public_id, 'product' => $this->fa->soda->public_id])
        ->and($category->metadata_json['created_name'])->toBe('Combos secretos')
        ->and(json_encode($availability->metadata_json))->not->toContain('available');
});

it('dono lê a trilha do próprio estabelecimento; nada de outro tenant aparece', function () {
    // Registro do tenant B com o mesmo tipo de ação.
    AuditLog::query()->create(['tenant_id' => $this->b->id, 'action' => 'categories.store', 'metadata_json' => ['created_name' => 'De B']]);
    AuditLog::query()->create(['tenant_id' => null, 'action' => 'tenant.created']);

    withSession($this->session)->postJson(tenantUrl($this->a, 'merchant/categories'), ['menu_id' => $this->fa->menu->public_id, 'name' => 'De A'])->assertCreated();

    $data = withSession($this->session)->getJson(tenantUrl($this->a, 'merchant/audit'))->assertOk()->json('data');
    $text = json_encode($data);

    expect($text)->toContain('De A')->not->toContain('De B')->not->toContain('tenant.created')
        ->and(collect($data)->firstWhere('action', 'categories.store')['actor']['name'])->toBe($this->owner->name);

    withSession($this->session)->getJson(tenantUrl($this->a, 'merchant/audit?action=auth.'))->assertOk()
        ->assertJsonPath('data.0.action', 'auth.merchant.login');
});

it('gerente e atendente não veem a auditoria', function () {
    foreach ([Role::MANAGER, Role::ATTENDANT] as $role) {
        $session = merchantLogin($this->a, makeMember($this->a, role: $role));
        withSession($session)->getJson(tenantUrl($this->a, 'merchant/audit'))->assertForbidden();
    }
});
