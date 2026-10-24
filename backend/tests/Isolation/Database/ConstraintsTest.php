<?php

use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\TenantUser;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Exceptions\CrossTenantWrite;
use App\Modules\Tenancy\Models\TenantDomain;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
| Garantias no próprio banco, independentes do Eloquent (cenários 7, 8, 11).
*/

beforeEach(function () {
    $this->a = makeTenant('tenant-a');
    $this->b = makeTenant('tenant-b');
    $this->branchB = makeBranch($this->b);
    $this->user = User::factory()->create();
    $this->ownerRoleId = Role::query()->whereNull('tenant_id')->where('key', Role::OWNER)->value('id');
});

it('7. FK composta impede vínculo do tenant A com unidade do tenant B (SQL puro)', function () {
    expect(fn () => DB::table('tenant_users')->insert([
        'tenant_id' => $this->a->id,
        'user_id' => $this->user->id,
        'role_id' => $this->ownerRoleId,
        'branch_id' => $this->branchB->id,
        'active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('8. vínculo duplicado (tenant, usuário, todas as unidades) é rejeitado pelo UNIQUE', function () {
    $row = [
        'tenant_id' => $this->a->id,
        'user_id' => $this->user->id,
        'role_id' => $this->ownerRoleId,
        'branch_id' => null,
        'active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ];

    DB::table('tenant_users')->insert($row);

    expect(fn () => DB::table('tenant_users')->insert($row))->toThrow(QueryException::class);
});

it('11. papel exclusivo do tenant B não pode ser atribuído no tenant A', function () {
    $roleOfB = Role::factory()->create(['tenant_id' => $this->b->id, 'key' => 'caixa-b']);

    expect(fn () => inTenant($this->a, fn () => TenantUser::query()->create([
        'user_id' => $this->user->id,
        'role_id' => $roleOfB->id,
    ])))->toThrow(CrossTenantWrite::class);

    // Papel do próprio tenant e papel global são aceitos.
    $roleOfA = Role::factory()->create(['tenant_id' => $this->a->id, 'key' => 'caixa-a']);
    inTenant($this->a, fn () => TenantUser::query()->create(['user_id' => $this->user->id, 'role_id' => $roleOfA->id]));

    expect(inTenant($this->a, fn () => TenantUser::query()->count()))->toBe(1);
});

it('cada tenant tem no máximo um domínio primário', function () {
    expect(fn () => asPlatform(fn () => TenantDomain::factory()->verified()->create([
        'tenant_id' => $this->a->id,
        'is_primary' => true,
    ])))->toThrow(QueryException::class);
});

it('hostname é único em toda a plataforma', function () {
    expect(fn () => asPlatform(fn () => TenantDomain::factory()->create([
        'tenant_id' => $this->b->id,
        'hostname' => hostOf($this->a),
    ])))->toThrow(QueryException::class);
});

it('chave de papel é única por escopo, inclusive entre papéis globais', function () {
    expect(fn () => Role::factory()->create(['tenant_id' => null, 'key' => Role::OWNER]))
        ->toThrow(QueryException::class);

    // A mesma chave pode existir como papel customizado de um tenant.
    Role::factory()->create(['tenant_id' => $this->a->id, 'key' => Role::OWNER]);
    expect(Role::query()->where('key', Role::OWNER)->count())->toBe(2);
});
