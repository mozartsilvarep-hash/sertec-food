<?php

namespace App\Modules\Platform\Tenants;

use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\TenantUser;
use App\Modules\Identity\Models\User;
use App\Modules\Platform\Audit\AuditLogger;
use App\Modules\Platform\Models\Plan;
use App\Modules\Platform\Models\Subscription;
use App\Modules\Tenancy\Enums\DomainType;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Branch;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantDomain;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Cria um tenant completo numa transação: tenant, subdomínio de plataforma
 * (verificado), unidade principal, proprietário e assinatura opcional.
 * Usado pelo comando tenant:create e, numa sprint futura, por POST /admin/tenants.
 */
final class TenantProvisioner
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array{tenant: Tenant, owner: User, owner_password: string|null}
     */
    public function provision(
        string $name,
        string $slug,
        string $ownerEmail,
        string $ownerName,
        ?string $planSlug = null,
        bool $activate = false,
        ?string $timezone = null,
    ): array {
        $slug = strtolower(trim($slug));
        $this->assertValidSlug($slug);

        return $this->context->runAsPlatform(fn () => DB::transaction(function () use (
            $name, $slug, $ownerEmail, $ownerName, $planSlug, $activate, $timezone
        ) {
            $tenant = Tenant::query()->create([
                'name' => $name,
                'slug' => $slug,
                'status' => $activate ? TenantStatus::Active : TenantStatus::Pending,
                'timezone' => $timezone ?? config('tenancy.default_timezone'),
            ]);

            TenantDomain::query()->create([
                'tenant_id' => $tenant->id,
                'hostname' => $slug.'.'.config('tenancy.base_domain'),
                'type' => DomainType::PlatformSubdomain,
                'verified_at' => now(),
                'is_primary' => true,
            ]);

            Branch::query()->create([
                'tenant_id' => $tenant->id,
                'name' => 'Matriz',
            ]);

            $ownerPassword = null;
            $owner = User::query()->where('email', strtolower($ownerEmail))->first();
            if ($owner === null) {
                $ownerPassword = Str::password(16, symbols: false);
                $owner = User::query()->create([
                    'name' => $ownerName,
                    'email' => $ownerEmail,
                    'password' => $ownerPassword,
                ]);
            }

            $ownerRole = Role::query()->whereNull('tenant_id')->where('key', Role::OWNER)->firstOrFail();
            TenantUser::query()->create([
                'tenant_id' => $tenant->id,
                'user_id' => $owner->id,
                'role_id' => $ownerRole->id,
                'branch_id' => null,
            ]);

            if ($planSlug !== null) {
                $plan = Plan::query()->where('slug', $planSlug)->firstOrFail();
                Subscription::query()->create([
                    'tenant_id' => $tenant->id,
                    'plan_id' => $plan->id,
                    'status' => Subscription::STATUS_ACTIVE,
                ]);
            }

            $this->audit->record('tenant.created', $tenant, [
                'slug' => $slug,
                'status' => $tenant->status->value,
                'plan' => $planSlug,
            ], tenant: $tenant);

            return ['tenant' => $tenant, 'owner' => $owner, 'owner_password' => $ownerPassword];
        }));
    }

    public function assertValidSlug(string $slug): void
    {
        if (! preg_match((string) config('tenancy.slug_pattern'), $slug)) {
            throw new InvalidArgumentException('Slug inválido: use 3-63 caracteres (a-z, 0-9, hífen).');
        }

        if (in_array($slug, (array) config('tenancy.reserved_slugs'), true)) {
            throw new InvalidArgumentException("Slug reservado: {$slug}.");
        }
    }
}
