<?php

namespace App\Modules\Identity\Models;

use App\Modules\Identity\Enums\PlatformRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;

/**
 * Usuário global. Não tem tenant_id: o acesso a cada tenant vem de tenant_users.
 *
 * @property int $id
 * @property string $public_id
 * @property string $name
 * @property string $email
 * @property PlatformRole|null $platform_role
 * @property bool $active
 * @property Carbon|null $last_login_at
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasUlids, Notifiable;

    protected $fillable = ['name', 'email', 'password', 'active'];

    protected $hidden = ['id', 'password', 'remember_token'];

    /**
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'active' => 'boolean',
            'platform_role' => PlatformRole::class,
        ];
    }

    public function setEmailAttribute(string $value): void
    {
        $this->attributes['email'] = strtolower(trim($value));
    }

    public function isPlatformStaff(): bool
    {
        return $this->platform_role !== null;
    }

    /**
     * Vínculos do usuário — escopados ao tenant do contexto (BelongsToTenant).
     *
     * @return HasMany<TenantUser, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(TenantUser::class);
    }

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }
}
