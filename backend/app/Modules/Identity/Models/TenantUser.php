<?php

namespace App\Modules\Identity\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Tenancy\Exceptions\CrossTenantWrite;
use App\Modules\Tenancy\Models\Branch;
use Database\Factories\TenantUserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Vínculo usuário ↔ tenant (opcionalmente restrito a uma unidade).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $user_id
 * @property int $role_id
 * @property int|null $branch_id
 * @property bool $active
 * @property-read Role $role
 * @property-read User $user
 */
class TenantUser extends Model
{
    /** @use HasFactory<TenantUserFactory> */
    use BelongsToTenant, HasFactory;

    protected $fillable = ['tenant_id', 'user_id', 'role_id', 'branch_id', 'active'];

    protected $hidden = ['branch_scope'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    protected static function booted(): void
    {
        // Registrados depois dos listeners do BelongsToTenant, então tenant_id
        // já foi preenchido pelo contexto quando estes rodam.
        $guardRole = function (TenantUser $membership): void {
            $role = Role::query()->find($membership->role_id);

            if ($role === null || ! $role->isUsableBy((int) $membership->tenant_id)) {
                throw new CrossTenantWrite('Role does not belong to this tenant.');
            }
        };

        static::creating($guardRole);
        static::updating($guardRole);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    protected static function newFactory(): TenantUserFactory
    {
        return TenantUserFactory::new();
    }
}
