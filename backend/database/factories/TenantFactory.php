<?php

namespace Database\Factories;

use App\Modules\Tenancy\Enums\DomainType;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantDomain;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Cria o tenant já com o subdomínio de plataforma verificado.
 *
 * @extends Factory<Tenant>
 */
class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::limit(Str::slug($name), 40, '').'-'.Str::lower(Str::random(5)),
            'status' => TenantStatus::Active,
            'timezone' => 'America/Sao_Paulo',
        ];
    }

    public function status(TenantStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Tenant $tenant): void {
            app(TenantContext::class)->runAsPlatform(fn () => TenantDomain::query()->create([
                'tenant_id' => $tenant->id,
                'hostname' => $tenant->slug.'.'.config('tenancy.base_domain'),
                'type' => DomainType::PlatformSubdomain,
                'verified_at' => now(),
                'is_primary' => true,
            ]));
        });
    }
}
