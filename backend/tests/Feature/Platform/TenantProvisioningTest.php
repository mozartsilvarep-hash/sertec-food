<?php

use App\Modules\Identity\Models\TenantUser;
use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\AuditLog;
use App\Modules\Platform\Models\Plan;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Branch;
use App\Modules\Tenancy\Models\Tenant;
use Database\Seeders\DemoSeeder;
use Database\Seeders\PlanSeeder;
use Illuminate\Support\Facades\Hash;

it('tenant:create provisiona tenant pendente com subdomínio, Matriz e proprietário', function () {
    $this->artisan('tenant:create', [
        'slug' => 'pizzaria-do-ze', 'name' => 'Pizzaria do Zé',
        '--owner-email' => 'ze@example.com', '--owner-name' => 'Zé',
    ])->expectsOutputToContain('status pending')->assertSuccessful();

    $tenant = Tenant::query()->where('slug', 'pizzaria-do-ze')->sole();
    expect($tenant->status)->toBe(TenantStatus::Pending);

    // Pendente = ainda não atende (ativação manual).
    $this->getJson(tenantUrl($tenant, 'storefront'))->assertForbidden();

    inTenant($tenant, function () {
        expect(Branch::query()->pluck('name')->all())->toBe(['Matriz']);
        expect(TenantUser::query()->with('role')->sole()->role->key)->toBe('owner');
    });

    expect(AuditLog::query()->where('action', 'tenant.created')->sole()->tenant_id)->toBe($tenant->id);
});

it('tenant:create com --activate e --plan cria assinatura ativa', function () {
    $this->seed(PlanSeeder::class);

    $this->artisan('tenant:create', [
        'slug' => 'burger-x', 'name' => 'Burger X', '--owner-email' => 'b@example.com',
        '--owner-name' => 'B', '--plan' => 'site-cardapio', '--activate' => true,
    ])->assertSuccessful();

    $tenant = Tenant::query()->where('slug', 'burger-x')->sole();
    $this->getJson(tenantUrl($tenant, 'storefront'))->assertOk();

    $owner = User::query()->where('email', 'b@example.com')->sole();
    expect(Plan::query()->where('slug', 'site-cardapio')->sole()->includes('site_mode'))->toBeTrue();
    expect($owner->platform_role)->toBeNull();
});

it('recusa slug reservado ou inválido', function (string $slug) {
    $this->artisan('tenant:create', [
        'slug' => $slug, 'name' => 'X', '--owner-email' => 'x@example.com', '--owner-name' => 'X',
    ])->assertFailed();

    expect(Tenant::query()->count())->toBe(0);
})->with(['admin', 'painel', 'www', 'ab', 'Com Espaço', '-hifen', 'acentuação']);

it('seeders de demonstração geram dados e senhas aleatórias', function () {
    $this->seed(PlanSeeder::class);
    $this->seed(DemoSeeder::class);

    expect(Tenant::query()->pluck('slug')->sort()->values()->all())->toBe(['burger-demo', 'pizzaria-demo']);
    $consultant = User::query()->where('email', 'consultor@sertec.localhost')->sole();
    expect(Hash::check('password', $consultant->password))->toBeFalse();
});
