<?php

use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\TenantUser;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Branch;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\TenantContext;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function () {
        $this->seed([PermissionSeeder::class, RoleSeeder::class]);
    })
    ->in('Feature', 'Isolation', 'Arch');

/*
|--------------------------------------------------------------------------
| Fixtures multi-tenant
|--------------------------------------------------------------------------
*/

function ctx(): TenantContext
{
    return app(TenantContext::class);
}

/**
 * @template T
 *
 * @param  Closure(): T  $callback
 * @return T
 */
function asPlatform(Closure $callback): mixed
{
    return ctx()->runAsPlatform($callback);
}

/**
 * @template T
 *
 * @param  Closure(): T  $callback
 * @return T
 */
function inTenant(Tenant $tenant, Closure $callback): mixed
{
    return ctx()->run($tenant, $callback);
}

function makeTenant(?string $slug = null, TenantStatus $status = TenantStatus::Active): Tenant
{
    $attributes = ['status' => $status];
    if ($slug !== null) {
        $attributes['slug'] = $slug;
    }

    return Tenant::factory()->create($attributes);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function makeBranch(Tenant $tenant, array $attributes = []): Branch
{
    return asPlatform(fn () => Branch::factory()->create(['tenant_id' => $tenant->id, ...$attributes]));
}

/**
 * Cria (ou reaproveita) usuário e o vincula ao tenant com um papel global.
 */
function makeMember(Tenant $tenant, ?User $user = null, string $role = Role::OWNER, ?Branch $branch = null): User
{
    $user ??= User::factory()->create();
    $roleModel = Role::query()->whereNull('tenant_id')->where('key', $role)->firstOrFail();

    asPlatform(fn () => TenantUser::factory()->create([
        'tenant_id' => $tenant->id,
        'user_id' => $user->id,
        'role_id' => $roleModel->id,
        'branch_id' => $branch?->id,
    ]));

    return $user;
}

function hostOf(Tenant $tenant): string
{
    return $tenant->slug.'.'.config('tenancy.base_domain');
}

function tenantUrl(Tenant $tenant, string $path): string
{
    return 'http://'.hostOf($tenant).'/api/v1/'.ltrim($path, '/');
}

function adminUrl(string $path): string
{
    return 'http://'.config('tenancy.admin_host').'/api/v1/'.ltrim($path, '/');
}

/**
 * Faz login real pelo endpoint e devolve o ID de sessão emitido.
 */
function merchantLogin(Tenant $tenant, User $user, string $password = 'password'): string
{
    // Sessão nova: não reaproveita o cookie de outro login do mesmo teste.
    $response = test()->withCookie((string) config('session.cookie'), '')->postJson(tenantUrl($tenant, 'merchant/auth/login'), [
        'email' => $user->email,
        'password' => $password,
    ]);
    $response->assertOk();

    return sessionIdFrom($response);
}

function adminLogin(User $user, string $password = 'password'): string
{
    $response = test()->withCookie((string) config('session.cookie'), '')->postJson(adminUrl('admin/auth/login'), [
        'email' => $user->email,
        'password' => $password,
    ]);
    $response->assertOk();

    return sessionIdFrom($response);
}

function sessionIdFrom(TestResponse $response): string
{
    $cookie = $response->getCookie((string) config('session.cookie'));
    expect($cookie)->not->toBeNull();

    return (string) $cookie->getValue();
}

/**
 * Requisição JSON reapresentando um cookie de sessão (como o navegador faria).
 */
function withSession(string $sessionId): TestCase
{
    /** @var TestCase $test */
    $test = test();
    // Cada requisição autentica de novo pelo cookie (como em produção): sem
    // isto, o guard guardaria o usuário da requisição anterior do teste.
    app('auth')->forgetGuards();
    app(TenantContext::class)->clear();

    return $test->withCredentials()->withCookie((string) config('session.cookie'), $sessionId);
}
