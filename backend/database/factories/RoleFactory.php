<?php

namespace Database\Factories;

use App\Modules\Identity\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Role>
 */
class RoleFactory extends Factory
{
    protected $model = Role::class;

    public function definition(): array
    {
        return [
            'tenant_id' => null,
            'key' => 'role-'.fake()->unique()->lexify('??????'),
            'name' => fake()->jobTitle(),
        ];
    }
}
