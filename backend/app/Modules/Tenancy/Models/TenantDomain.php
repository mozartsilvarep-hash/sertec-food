<?php

namespace App\Modules\Tenancy\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Tenancy\Enums\DomainType;
use Database\Factories\TenantDomainFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string $hostname
 * @property DomainType $type
 * @property Carbon|null $verified_at
 * @property bool $is_primary
 */
class TenantDomain extends Model
{
    /** @use HasFactory<TenantDomainFactory> */
    use BelongsToTenant, HasFactory;

    protected $fillable = ['tenant_id', 'hostname', 'type', 'verified_at', 'is_primary'];

    protected $hidden = ['verification_token_hash', 'primary_tenant_id'];

    protected function casts(): array
    {
        return [
            'type' => DomainType::class,
            'verified_at' => 'datetime',
            'is_primary' => 'boolean',
        ];
    }

    public function setHostnameAttribute(string $value): void
    {
        $this->attributes['hostname'] = self::normalize($value);
    }

    public static function normalize(string $host): string
    {
        return rtrim(strtolower(trim($host)), '.');
    }

    protected static function newFactory(): TenantDomainFactory
    {
        return TenantDomainFactory::new();
    }
}
