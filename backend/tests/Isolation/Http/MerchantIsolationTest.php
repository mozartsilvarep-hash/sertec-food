<?php

use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\TenantUser;
use App\Modules\Identity\Models\User;

/*
| Cenários 16–21 e 23: isolamento no painel do restaurante.
*/

beforeEach(function () {
    $this->a = makeTenant('tenant-a');
    $this->b = makeTenant('tenant-b');
    $this->branchA1 = makeBranch($this->a, ['name' => 'A1']);
    $this->branchA2 = makeBranch($this->a, ['name' => 'A2']);
    $this->branchB1 = makeBranch($this->b, ['name' => 'B1']);
    $this->ownerA = makeMember($this->a, User::factory()->create(['email' => 'dono-a@example.com']));
});

it('16. usuário só de A não consegue logar no host de B (mesma resposta de senha errada)', function () {
    $semVinculo = $this->postJson(tenantUrl($this->b, 'merchant/auth/login'), [
        'email' => 'dono-a@example.com', 'password' => 'password',
    ]);
    $senhaErrada = $this->postJson(tenantUrl($this->b, 'merchant/auth/login'), [
        'email' => 'ninguem@example.com', 'password' => 'password',
    ]);

    $semVinculo->assertStatus(422);
    $senhaErrada->assertStatus(422);
    expect($semVinculo->json('code'))->toBe($senhaErrada->json('code'))
        ->and($semVinculo->json('message'))->toBe($senhaErrada->json('message'));
});

it('17. cookie de sessão emitido em A e reapresentado em B é recusado e encerrado', function () {
    $session = merchantLogin($this->a, $this->ownerA);

    withSession($session)->getJson(tenantUrl($this->a, 'merchant/me'))->assertOk();
    withSession($session)->getJson(tenantUrl($this->b, 'merchant/me'))
        ->assertUnauthorized()
        ->assertJsonPath('code', 'unauthenticated');

    // A sessão foi invalidada: não volta a funcionar nem no host original.
    withSession($session)->getJson(tenantUrl($this->a, 'merchant/me'))->assertUnauthorized();
});

it('17. sessão de A não vale em B mesmo para usuário membro dos dois', function () {
    $both = makeMember($this->a, User::factory()->create(), Role::MANAGER);
    makeMember($this->b, $both, Role::MANAGER);

    $session = merchantLogin($this->a, $both);

    withSession($session)->getJson(tenantUrl($this->b, 'merchant/branches'))->assertUnauthorized();
});

it('18. com ID conhecido, usuário de A não lê unidade de B (404)', function () {
    $session = merchantLogin($this->a, $this->ownerA);

    withSession($session)->getJson(tenantUrl($this->a, 'merchant/branches/'.$this->branchB1->public_id))
        ->assertNotFound()
        ->assertJsonPath('code', 'not_found');

    // ID interno numérico também não funciona (rota só aceita ULID público).
    withSession($session)->getJson(tenantUrl($this->a, 'merchant/branches/'.$this->branchB1->id))
        ->assertNotFound();

    withSession($session)->getJson(tenantUrl($this->a, 'merchant/branches/'.$this->branchA1->public_id))
        ->assertOk()
        ->assertJsonPath('data.name', 'A1')
        ->assertJsonMissingPath('data.tenant_id');
});

it('19. usuário de A e B vê somente os dados do host em que logou', function () {
    $both = makeMember($this->a, User::factory()->create(), Role::MANAGER);
    makeMember($this->b, $both, Role::MANAGER);

    $sessionA = merchantLogin($this->a, $both);
    $namesA = collect(withSession($sessionA)->getJson(tenantUrl($this->a, 'merchant/branches'))->json('data'))->pluck('name');

    $sessionB = merchantLogin($this->b, $both);
    $namesB = collect(withSession($sessionB)->getJson(tenantUrl($this->b, 'merchant/branches'))->json('data'))->pluck('name');

    expect($namesA->all())->toBe(['A1', 'A2'])
        ->and($namesB->all())->toBe(['B1']);

    withSession($sessionB)->getJson(tenantUrl($this->b, 'merchant/me'))
        ->assertJsonPath('data.tenant.slug', 'tenant-b');
});

it('20. funcionário restrito à unidade A1 não vê a unidade A2 do mesmo tenant', function () {
    $attendant = makeMember($this->a, User::factory()->create(), Role::ATTENDANT, $this->branchA1);
    $session = merchantLogin($this->a, $attendant);

    $names = collect(withSession($session)->getJson(tenantUrl($this->a, 'merchant/branches'))->json('data'))->pluck('name');
    expect($names->all())->toBe(['A1']);

    withSession($session)->getJson(tenantUrl($this->a, 'merchant/branches/'.$this->branchA2->public_id))->assertNotFound();
    withSession($session)->getJson(tenantUrl($this->a, 'merchant/me'))->assertJsonPath('data.all_branches', false);
});

it('21. usuário sem a permissão necessária recebe 403', function () {
    $kitchen = makeMember($this->a, User::factory()->create(), Role::KITCHEN);
    $session = merchantLogin($this->a, $kitchen);

    withSession($session)->getJson(tenantUrl($this->a, 'merchant/branches'))
        ->assertForbidden()
        ->assertJsonPath('code', 'forbidden');
});

it('vínculo desativado perde o acesso imediatamente', function () {
    $session = merchantLogin($this->a, $this->ownerA);

    asPlatform(fn () => TenantUser::query()
        ->where('user_id', $this->ownerA->id)->update(['active' => false]));

    withSession($session)->getJson(tenantUrl($this->a, 'merchant/me'))->assertForbidden();
});

it('23. erros seguem o formato padrão e não mencionam outro tenant', function () {
    $session = merchantLogin($this->a, $this->ownerA);

    $response = withSession($session)
        ->getJson(tenantUrl($this->a, 'merchant/branches/'.$this->branchB1->public_id))
        ->assertNotFound()
        ->assertJsonStructure(['code', 'message', 'details', 'request_id']);

    $body = $response->getContent();
    expect($body)->not->toContain('tenant-b')
        ->and($body)->not->toContain($this->b->name)
        ->and($body)->not->toContain('B1')
        ->and($response->json('request_id'))->toBe($response->headers->get('X-Request-Id'));
});
