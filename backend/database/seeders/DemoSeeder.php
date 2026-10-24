<?php

namespace Database\Seeders;

use App\Modules\Identity\Enums\PlatformRole;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\TenantUser;
use App\Modules\Identity\Models\User;
use App\Modules\Platform\Tenants\TenantProvisioner;
use App\Modules\Tenancy\Models\Branch;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Dados de demonstração para desenvolvimento local. Senhas aleatórias,
 * exibidas uma única vez no terminal. Recusa rodar fora de local/testing.
 */
class DemoSeeder extends Seeder
{
    /** @var list<array{0:string,1:string,2:string}> */
    private array $credentials = [];

    public function run(TenantProvisioner $provisioner, TenantContext $context): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('DemoSeeder só pode rodar em local/testing.');
        }

        if (Tenant::query()->where('slug', 'pizzaria-demo')->exists()) {
            $this->command?->info('Dados de demonstração já existem; nada a fazer.');

            return;
        }

        $admin = $this->user('Equipe SerTec', 'admin@sertec.localhost', (string) config('tenancy.admin_host'));
        $admin->forceFill(['platform_role' => PlatformRole::Superadmin])->save();

        $pizzaria = $this->tenant($provisioner, 'pizzaria-demo', 'Pizzaria Demo', 'dono@pizzaria.localhost', 'site-cardapio');
        $burger = $this->tenant($provisioner, 'burger-demo', 'Burger Demo', 'dono@burger.localhost', 'cardapio');

        $context->runAsPlatform(function () use ($pizzaria, $burger): void {
            $centro = Branch::query()->create(['tenant_id' => $pizzaria->id, 'name' => 'Unidade Ponta Verde']);

            $attendant = $this->user('Atendente Pizzaria', 'atendente@pizzaria.localhost', 'pizzaria-demo.'.config('tenancy.base_domain').'/painel (só Ponta Verde)');
            $this->member($pizzaria, $attendant, Role::ATTENDANT, $centro->id);

            // Mesmo usuário em dois tenants: valida isolamento por host.
            $consultant = $this->user('Consultor Multi-loja', 'consultor@sertec.localhost', 'pizzaria-demo e burger-demo (/painel)');
            $this->member($pizzaria, $consultant, Role::MANAGER, null);
            $this->member($burger, $consultant, Role::MANAGER, null);
        });

        $this->call(DemoCatalogSeeder::class);

        $this->command?->warn('Credenciais de demonstração (exibidas uma única vez):');
        $this->command?->table(['E-mail', 'Senha', 'Onde entra'], $this->credentials);
    }

    private function tenant(TenantProvisioner $provisioner, string $slug, string $name, string $ownerEmail, string $plan): Tenant
    {
        $result = $provisioner->provision($name, $slug, $ownerEmail, 'Proprietário '.$name, $plan, activate: true);
        $this->credentials[] = [$ownerEmail, (string) $result['owner_password'], $slug.'.'.config('tenancy.base_domain').'/painel'];

        return $result['tenant'];
    }

    private function user(string $name, string $email, string $where): User
    {
        $password = Str::password(16, symbols: false);
        $this->credentials[] = [$email, $password, $where];

        return User::query()->create(['name' => $name, 'email' => $email, 'password' => $password]);
    }

    private function member(Tenant $tenant, User $user, string $roleKey, ?int $branchId): void
    {
        $role = Role::query()->whereNull('tenant_id')->where('key', $roleKey)->firstOrFail();

        TenantUser::query()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'role_id' => $role->id,
            'branch_id' => $branchId,
        ]);
    }
}
