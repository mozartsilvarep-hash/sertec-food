<?php

namespace App\Modules\Tenancy\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Database\Factories\BranchFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Unidade (loja física) de um tenant.
 *
 * @property int $id
 * @property string $public_id
 * @property int $tenant_id
 * @property string $name
 * @property array<string, mixed>|null $address_json
 * @property string|null $phone
 * @property bool $active
 * @property bool $pickup_enabled
 * @property bool $delivery_enabled
 * @property int $pickup_eta_minutes
 */
class Branch extends Model
{
    /** @use HasFactory<BranchFactory> */
    use BelongsToTenant, HasFactory, HasUlids;

    protected $fillable = ['tenant_id', 'name', 'address_json', 'phone', 'active', 'pickup_enabled', 'delivery_enabled', 'pickup_eta_minutes'];

    /**
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    protected function casts(): array
    {
        return [
            'address_json' => 'array',
            'active' => 'boolean',
            'pickup_enabled' => 'boolean',
            'delivery_enabled' => 'boolean',
            'pickup_eta_minutes' => 'integer',
        ];
    }

    protected static function newFactory(): BranchFactory
    {
        return BranchFactory::new();
    }
}
