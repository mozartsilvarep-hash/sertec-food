<?php

namespace Database\Factories;

use App\Modules\Tenancy\Enums\DomainType;
use App\Modules\Tenancy\Models\TenantDomain;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Uso em modo plataforma ou dentro de TenantContext::run().
 *
 * @extends Factory<TenantDomain>
 */
class TenantDomainFactory extends Factory
{
    protected $model = TenantDomain::class;

    public function definition(): array
    {
        return [
            'hostname' => fake()->unique()->domainWord().'-'.fake()->randomNumber(5).'.com.br',
            'type' => DomainType::Custom,
            'verified_at' => null,
            'is_primary' => false,
        ];
    }

    public function verified(): static
    {
        return $this->state(fn () => ['verified_at' => now()]);
    }
}
