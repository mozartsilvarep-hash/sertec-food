<?php

namespace Database\Factories;

use App\Modules\Tenancy\Models\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Uso em modo plataforma (->for($tenant)) ou dentro de TenantContext::run().
 *
 * @extends Factory<Branch>
 */
class BranchFactory extends Factory
{
    protected $model = Branch::class;

    public function definition(): array
    {
        return [
            'name' => 'Unidade '.fake()->unique()->city(),
            'phone' => fake()->numerify('(82) 9####-####'),
            'address_json' => [
                'street' => fake()->streetName(),
                'number' => (string) fake()->buildingNumber(),
                'district' => fake()->word(),
                'city' => 'Maceió',
                'state' => 'AL',
            ],
            'active' => true,
        ];
    }
}
