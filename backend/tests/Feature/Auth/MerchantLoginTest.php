<?php

use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\AuditLog;

beforeEach(function () {
    $this->tenant = makeTenant('pizzaria');
    $this->owner = makeMember($this->tenant, User::factory()->create(['email' => 'dono@example.com']));
});

it('autentica, regenera a sessão e devolve o perfil do tenant atual', function () {
    $before = $this->getJson(tenantUrl($this->tenant, 'storefront'));

    $response = $this->postJson(tenantUrl($this->tenant, 'merchant/auth/login'), [
        'email' => 'DONO@example.com', 'password' => 'password',
    ]);

    $response->assertOk()
        ->assertJsonPath('data.user.email', 'dono@example.com')
        ->assertJsonPath('data.tenant.slug', 'pizzaria')
        ->assertJsonPath('data.roles', ['owner'])
        ->assertJsonPath('data.all_branches', true)
        ->assertJsonMissingPath('data.user.password');

    expect($response->json('data.permissions'))->toContain('branches.view', 'staff.manage');
    expect(sessionIdFrom($response))->not->toBe((string) $before->getCookie((string) config('session.cookie'))?->getValue());
    expect($this->owner->fresh()->last_login_at)->not->toBeNull();
});

it('registra auditoria de login com e sem sucesso, sem gravar o e-mail em claro', function () {
    $this->postJson(tenantUrl($this->tenant, 'merchant/auth/login'), ['email' => 'dono@example.com', 'password' => 'errada'])
        ->assertStatus(422);
    merchantLogin($this->tenant, $this->owner);

    $failed = AuditLog::query()->where('action', 'auth.merchant.login_failed')->sole();
    $ok = AuditLog::query()->where('action', 'auth.merchant.login')->sole();

    expect($failed->tenant_id)->toBe($this->tenant->id)
        ->and(json_encode($failed->metadata_json))->not->toContain('dono@example.com')
        ->and($ok->actor_user_id)->toBe($this->owner->id)
        ->and($ok->request_id)->not->toBeNull();
});

it('recusa usuário desativado com a mesma resposta genérica', function () {
    $this->owner->forceFill(['active' => false])->save();

    $this->postJson(tenantUrl($this->tenant, 'merchant/auth/login'), ['email' => 'dono@example.com', 'password' => 'password'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'invalid_credentials');
});

it('valida o payload no formato padrão de erro', function () {
    $this->postJson(tenantUrl($this->tenant, 'merchant/auth/login'), ['email' => 'nao-e-email'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonStructure(['details' => ['fields' => ['email', 'password']], 'request_id']);
});

it('limita tentativas de login (429)', function () {
    foreach (range(1, 5) as $_) {
        $this->postJson(tenantUrl($this->tenant, 'merchant/auth/login'), ['email' => 'dono@example.com', 'password' => 'x'])
            ->assertStatus(422);
    }

    $this->postJson(tenantUrl($this->tenant, 'merchant/auth/login'), ['email' => 'dono@example.com', 'password' => 'password'])
        ->assertStatus(429)
        ->assertJsonPath('code', 'too_many_requests')
        ->assertHeader('Retry-After');
});

it('logout encerra a sessão', function () {
    $session = merchantLogin($this->tenant, $this->owner);

    withSession($session)->postJson(tenantUrl($this->tenant, 'merchant/auth/logout'))->assertNoContent();
    withSession($session)->getJson(tenantUrl($this->tenant, 'merchant/me'))->assertUnauthorized();
});

it('rotas protegidas exigem autenticação', function () {
    $this->getJson(tenantUrl($this->tenant, 'merchant/me'))
        ->assertUnauthorized()
        ->assertJsonPath('code', 'unauthenticated');
});
