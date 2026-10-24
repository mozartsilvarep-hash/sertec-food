<?php

namespace Database\Factories;

use App\Modules\Identity\Enums\PlatformRole;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Senha padrão "password" existe SOMENTE para testes automatizados.
 * Seeders de demonstração geram senhas aleatórias.
 *
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    protected static ?string $password;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'active' => true,
            'remember_token' => Str::random(10),
        ];
    }

    public function platformAdmin(PlatformRole $role = PlatformRole::Superadmin): static
    {
        return $this->state(fn () => ['platform_role' => $role]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['active' => false]);
    }
}
