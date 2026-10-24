<?php

namespace App\Modules\Catalog\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $public_id
 * @property int $tenant_id
 * @property int $option_group_id
 * @property string $name
 * @property string|null $description
 * @property string $price_delta
 * @property int $max_quantity
 * @property int $sort_order
 * @property bool $active
 * @property-read OptionGroup $group
 * @property-read Collection<int, OptionVariantPrice> $variantPrices
 */
class Option extends Model
{
    use BelongsToTenant, HasUlids;

    protected $fillable = [
        'tenant_id', 'option_group_id', 'name', 'description', 'price_delta', 'max_quantity', 'sort_order', 'active',
    ];

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    protected function casts(): array
    {
        return [
            'price_delta' => 'decimal:2',
            'max_quantity' => 'integer',
            'sort_order' => 'integer',
            'active' => 'boolean',
        ];
    }

    /** @return BelongsTo<OptionGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(OptionGroup::class, 'option_group_id');
    }

    /** @return HasMany<OptionVariantPrice, $this> */
    public function variantPrices(): HasMany
    {
        return $this->hasMany(OptionVariantPrice::class);
    }
}
