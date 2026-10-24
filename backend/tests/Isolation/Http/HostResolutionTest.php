<?php

use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\TenantDomain;

/*
| Cenários 14 e 15: o tenant vem exclusivamente do Host verificado.
*/

beforeEach(function () {
    $this->a = makeTenant('tenant-a');
    $this->b = makeTenant('tenant-b');
});

it('14. resolve o tenant pelo subdomínio de plataforma', function () {
    $this->getJson(tenantUrl($this->a, 'storefront'))
        ->assertOk()
        ->assertJsonPath('data.name', $this->a->name)
        ->assertJsonPath('data.slug', 'tenant-a')
        ->assertJsonPath('data.timezone', 'America/Sao_Paulo')
        ->assertJsonPath('data.branches', []);
});

it('14. host desconhecido retorna 404', function () {
    $this->getJson('http://nao-existe.'.config('tenancy.base_domain').'/api/v1/storefront')
        ->assertNotFound()
        ->assertJsonPath('code', 'not_found');
});

it('14. domínio próprio só resolve depois de verificado', function () {
    $domain = asPlatform(fn () => TenantDomain::factory()->create([
        'tenant_id' => $this->a->id,
        'hostname' => 'Pedidos.Restaurante-A.com.br',
    ]));

    expect($domain->hostname)->toBe('pedidos.restaurante-a.com.br');

    $this->getJson('http://pedidos.restaurante-a.com.br/api/v1/storefront')->assertNotFound();

    asPlatform(fn () => $domain->forceFill(['verified_at' => now()])->save());

    $this->getJson('http://pedidos.restaurante-a.com.br/api/v1/storefront')
        ->assertOk()
        ->assertJsonPath('data.slug', 'tenant-a');
});

it('14. tenant suspenso ou pendente responde 403 tenant_unavailable', function (TenantStatus $status) {
    $tenant = makeTenant('tenant-x', $status);

    $this->getJson(tenantUrl($tenant, 'storefront'))
        ->assertForbidden()
        ->assertJsonPath('code', 'tenant_unavailable');
})->with([TenantStatus::Suspended, TenantStatus::Pending, TenantStatus::Cancelled]);

it('14. host do painel SerTec não é tratado como tenant', function () {
    $this->getJson(adminUrl('storefront'))->assertNotFound();
});

it('15. X-Tenant-Id, query e body com tenant_id são ignorados', function () {
    $user = makeMember($this->a);
    $session = merchantLogin($this->a, $user);
    makeBranch($this->a, ['name' => 'A1']);
    makeBranch($this->b, ['name' => 'B1']);

    $response = withSession($session)
        ->withHeader('X-Tenant-Id', (string) $this->b->id)
        ->getJson(tenantUrl($this->a, 'merchant/branches?tenant_id='.$this->b->id.'&tenant='.$this->b->public_id));

    $response->assertOk();
    expect(collect($response->json('data'))->pluck('name')->all())->toBe(['A1']);

    $this->withHeader('X-Tenant-Id', (string) $this->b->id)
        ->getJson(tenantUrl($this->a, 'storefront?tenant_id='.$this->b->id))
        ->assertJsonPath('data.slug', 'tenant-a');
});

it('15. login informando tenant_id de outro estabelecimento continua preso ao host', function () {
    $userOfB = makeMember($this->b, User::factory()->create(['email' => 'so-b@example.com']));

    $this->postJson(tenantUrl($this->a, 'merchant/auth/login'), [
        'email' => $userOfB->email,
        'password' => 'password',
        'tenant_id' => $this->b->id,
    ])->assertStatus(422)->assertJsonPath('code', 'invalid_credentials');
});
