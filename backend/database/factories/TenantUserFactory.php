<?php

namespace Database\Factories;

use App\Modules\Identity\Models\TenantUser;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Uso em modo plataforma ou dentro de TenantContext::run().
 *
 * @extends Factory<TenantUser>
 */
class TenantUserFactory extends Factory
{
    protected $model = TenantUser::class;

    public function definition(): array
    {
        return [
            'branch_id' => null,
            'active' => true,
        ];
    }
}
