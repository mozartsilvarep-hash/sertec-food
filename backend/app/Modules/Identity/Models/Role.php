<?php

namespace App\Modules\Identity\Models;

use App\Modules\Tenancy\TenantContext;
use Database\Factories\RoleFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Papel global (tenant_id NULL) ou customizado de um tenant.
 *
 * Exceção documentada ao BelongsToTenant: papéis globais precisam ser
 * visíveis a todos os tenants. O escopo "visibleToCurrentTenant" aplica
 * a regra "global OU do tenant atual"; TenantUser valida a mesma regra
 * na gravação.
 *
 * @property int $id
 * @property int|null $tenant_id
 * @property string $key
 * @property string $name
 */
class Role extends Model
{
    /** @use HasFactory<RoleFactory> */
    use HasFactory;

    public const OWNER = 'owner';

    public const MANAGER = 'manager';

    public const ATTENDANT = 'attendant';

    public const KITCHEN = 'kitchen';

    protected $fillable = ['tenant_id', 'key', 'name'];

    protected $hidden = ['tenant_scope'];

    /**
     * @return BelongsToMany<Permission, $this>
     */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permissions');
    }

    /**
     * @param  Builder<Role>  $query
     */
    public function scopeVisibleToCurrentTenant(Builder $query): void
    {
        $tenantId = app(TenantContext::class)->id();

        $query->where(fn (Builder $q) => $q->whereNull('tenant_id')->orWhere('tenant_id', $tenantId));
    }

    public function isUsableBy(int $tenantId): bool
    {
        return $this->tenant_id === null || $this->tenant_id === $tenantId;
    }

    protected static function newFactory(): RoleFactory
    {
        return RoleFactory::new();
    }
}
