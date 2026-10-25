<?php

use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\TenantUser;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Models\Branch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    $this->a = makeTenant('tenant-a');
    $this->b = makeTenant('tenant-b');
    $this->branch = makeBranch($this->a, ['name' => 'Centro']);
    $this->owner = makeMember($this->a);
    $this->session = merchantLogin($this->a, $this->owner);
});

function staff(object $t, string $method, string $path = '', array $body = [], ?string $session = null)
{
    return withSession($session ?? $t->session)->{$method.'Json'}(tenantUrl($t->a, 'merchant/staff'.$path), $body);
}

it('dono adiciona atendente com senha temporária; ela entra só na unidade dela', function () {
    $res = staff($this, 'post', '', [
        'name' => 'Ana Atendente', 'email' => 'Ana@Pizzaria.test', 'role' => 'attendant', 'branch_id' => $this->branch->public_id,
    ])->assertCreated()
        ->assertJsonPath('data.email', 'ana@pizzaria.test')
        ->assertJsonPath('data.role', 'attendant')
        ->assertJsonPath('data.branch.name', 'Centro');

    $password = $res->json('data.temporary_password');
    expect($password)->toBeString()->toHaveLength(12);

    staff($this, 'get')->assertOk()->assertJsonCount(2, 'data');
    staff($this, 'post', '', ['name' => 'Ana', 'email' => 'ana@pizzaria.test', 'role' => 'attendant'])
        ->assertStatus(409)->assertJsonPath('code', 'already_member');

    $session = merchantLogin($this->a, User::query()->where('email', 'ana@pizzaria.test')->sole(), $password);
    withSession($session)->getJson(tenantUrl($this->a, 'merchant/branches'))->assertOk()->assertJsonCount(1, 'data');
});

it('não cria dono, não altera o próprio vínculo nem o de proprietários', function () {
    staff($this, 'post', '', ['name' => 'Novo Dono', 'email' => 'x@y.test', 'role' => 'owner'])->assertStatus(422);
    staff($this, 'patch', "/{$this->owner->public_id}", ['role' => 'attendant'])->assertStatus(409)->assertJsonPath('code', 'self_change');

    $otherOwner = makeMember($this->a);
    staff($this, 'patch', "/{$otherOwner->public_id}", ['active' => false])->assertStatus(409)->assertJsonPath('code', 'owner_protected');
    staff($this, 'delete', "/{$otherOwner->public_id}")->assertStatus(409);
});

it('pessoa que já tem conta em outro restaurante entra sem senha nova, e o dono não pode redefinir a senha dela', function () {
    $shared = User::factory()->create(['email' => 'joao@x.test', 'password' => 'senha-original-123']);
    makeMember($this->b, $shared, Role::ATTENDANT);

    staff($this, 'post', '', ['name' => 'Outro Nome', 'email' => 'joao@x.test', 'role' => 'kitchen'])->assertCreated()
        ->assertJsonPath('data.temporary_password', null)
        ->assertJsonPath('data.name', $shared->name); // nome da conta não é sobrescrito

    staff($this, 'post', "/{$shared->public_id}/password")->assertStatus(409)->assertJsonPath('code', 'shared_account');
    expect(Hash::check('senha-original-123', $shared->fresh()->password))->toBeTrue();
});

it('redefinir senha de conta exclusiva gera temporária e encerra sessões; desativar corta o acesso', function () {
    $ana = makeMember($this->a, role: Role::ATTENDANT);
    DB::table('sessions')->insert(['id' => str_repeat('s', 40), 'user_id' => $ana->id, 'payload' => '', 'last_activity' => time()]);

    $pw = staff($this, 'post', "/{$ana->public_id}/password")->assertOk()->json('data.temporary_password');
    expect(Hash::check($pw, $ana->fresh()->password))->toBeTrue()
        ->and(DB::table('sessions')->where('user_id', $ana->id)->count())->toBe(0);

    staff($this, 'patch', "/{$ana->public_id}", ['active' => false])->assertOk()->assertJsonPath('data.active', false);
    $this->postJson(tenantUrl($this->a, 'merchant/auth/login'), ['email' => $ana->email, 'password' => $pw])
        ->assertStatus(422)->assertJsonPath('code', 'invalid_credentials');
});

it('remove da equipe; isolamento entre tenants e permissão', function () {
    $ana = makeMember($this->a, role: Role::ATTENDANT);
    $bob = makeMember($this->b, role: Role::ATTENDANT);

    staff($this, 'patch', "/{$bob->public_id}", ['role' => 'manager'])->assertNotFound();
    staff($this, 'delete', "/{$bob->public_id}")->assertNotFound();
    expect(asPlatform(fn () => TenantUser::query()->where('user_id', $bob->id)->count()))->toBe(1);

    staff($this, 'delete', "/{$ana->public_id}")->assertNoContent();
    expect(inTenant($this->a, fn () => TenantUser::query()->where('user_id', $ana->id)->count()))->toBe(0);

    $manager = merchantLogin($this->a, makeMember($this->a, role: Role::MANAGER));
    staff($this, 'get', session: $manager)->assertForbidden();
});

it('usuário troca a própria senha e as outras sessões caem', function () {
    DB::table('sessions')->insert(['id' => str_repeat('o', 40), 'user_id' => $this->owner->id, 'payload' => '', 'last_activity' => time()]);
    $url = tenantUrl($this->a, 'merchant/me/password');

    withSession($this->session)->postJson($url, ['current_password' => 'errada', 'password' => 'NovaSenha123', 'password_confirmation' => 'NovaSenha123'])
        ->assertStatus(422)->assertJsonPath('code', 'current_password_invalid');
    withSession($this->session)->postJson($url, ['current_password' => 'password', 'password' => 'curta', 'password_confirmation' => 'curta'])
        ->assertStatus(422)->assertJsonPath('code', 'validation_failed');
    withSession($this->session)->postJson($url, ['current_password' => 'password', 'password' => 'NovaSenha123', 'password_confirmation' => 'NovaSenha123'])
        ->assertNoContent();

    expect(Hash::check('NovaSenha123', $this->owner->fresh()->password))->toBeTrue()
        ->and(DB::table('sessions')->where('id', str_repeat('o', 40))->exists())->toBeFalse();
});

it('unidade: dono edita telefone e endereço; não desativa a última; atendente não edita', function () {
    $url = tenantUrl($this->a, "merchant/branches/{$this->branch->public_id}");
    withSession($this->session)->patchJson($url, [
        'name' => 'Centro Histórico', 'phone' => '82999887766', 'address' => ['street' => 'Rua do Sol', 'number' => '10', 'city' => 'Maceió', 'state' => 'AL', 'zip' => '57000-000'],
    ])->assertOk()->assertJsonPath('data.phone', '(82) 99988-7766')->assertJsonPath('data.address.city', 'Maceió');

    withSession($this->session)->patchJson($url, ['phone' => '123'])->assertStatus(422);

    // Única unidade ativa do tenant A? Desativa as outras primeiro.
    inTenant($this->a, fn () => Branch::query()->whereKeyNot($this->branch->id)->update(['active' => false]));
    withSession($this->session)->patchJson($url, ['active' => false])->assertStatus(409)->assertJsonPath('code', 'last_branch');

    $attendant = merchantLogin($this->a, makeMember($this->a, role: Role::ATTENDANT));
    withSession($attendant)->patchJson($url, ['name' => 'X'])->assertForbidden();
});
