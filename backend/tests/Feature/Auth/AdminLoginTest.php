<?php

use App\Modules\Identity\Enums\PlatformRole;
use App\Modules\Identity\Models\User;

it('equipe SerTec autentica no host admin', function () {
    $admin = User::factory()->platformAdmin(PlatformRole::Support)->create(['email' => 'suporte@sertec.test']);

    $session = adminLogin($admin);

    withSession($session)->getJson(adminUrl('admin/me'))
        ->assertOk()
        ->assertJsonPath('data.user.email', 'suporte@sertec.test')
        ->assertJsonPath('data.user.platform_role', 'support');
});

it('usuário rebaixado perde acesso ao painel na próxima requisição', function () {
    $admin = User::factory()->platformAdmin()->create();
    $session = adminLogin($admin);

    $admin->forceFill(['platform_role' => null])->save();

    withSession($session)->getJson(adminUrl('admin/me'))->assertForbidden();
});

it('logout do admin encerra a sessão', function () {
    $admin = User::factory()->platformAdmin()->create();
    $session = adminLogin($admin);

    withSession($session)->postJson(adminUrl('admin/auth/logout'))->assertNoContent();
    withSession($session)->getJson(adminUrl('admin/me'))->assertUnauthorized();
});
