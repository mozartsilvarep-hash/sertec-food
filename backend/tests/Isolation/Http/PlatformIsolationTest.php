<?php

use App\Modules\Identity\Models\User;

/*
| Cenário 22: rotas de plataforma isoladas por host e por papel.
*/

beforeEach(function () {
    $this->a = makeTenant('tenant-a');
});

it('22. rotas /admin em host de tenant respondem 404', function () {
    $admin = User::factory()->platformAdmin()->create();

    $this->postJson(tenantUrl($this->a, 'admin/auth/login'), ['email' => $admin->email, 'password' => 'password'])
        ->assertNotFound();
    $this->getJson(tenantUrl($this->a, 'admin/me'))->assertNotFound();
});

it('22. funcionário de restaurante não entra no painel SerTec', function () {
    $owner = makeMember($this->a);

    $this->postJson(adminUrl('admin/auth/login'), ['email' => $owner->email, 'password' => 'password'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'invalid_credentials');
});

it('22. sessão de merchant reapresentada no host admin não autentica', function () {
    $owner = makeMember($this->a);
    $session = merchantLogin($this->a, $owner);

    withSession($session)->getJson(adminUrl('admin/me'))->assertUnauthorized();
});

it('22. sessão de plataforma não abre o painel de um restaurante', function () {
    $admin = User::factory()->platformAdmin()->create();
    $session = adminLogin($admin);

    withSession($session)->getJson(adminUrl('admin/me'))->assertOk()->assertJsonPath('data.user.platform_role', 'superadmin');
    withSession($session)->getJson(tenantUrl($this->a, 'merchant/me'))->assertUnauthorized();
});

it('22. rotas de merchant no host admin respondem 404', function () {
    $this->getJson(adminUrl('merchant/me'))->assertNotFound();
});
