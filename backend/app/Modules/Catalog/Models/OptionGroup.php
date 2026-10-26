<?php

namespace App\Modules\Catalog\Models;

use App\Modules\Catalog\Enums\FractionPricingRule;
use App\Modules\Catalog\Enums\OptionGroupType;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Database\Factories\OptionGroupFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $public_id
 * @property int $tenant_id
 * @property int|null $owner_product_id combo dono do grupo (fora da lista geral)
 * @property string $name
 * @property OptionGroupType $type
 * @property int $min_select
 * @property int $max_select
 * @property int $free_quantity
 * @property FractionPricingRule|null $pricing_rule
 * @property bool $active
 * @property-read Collection<int, Option> $options
 */
class OptionGroup extends Model
{
    /** @use HasFactory<OptionGroupFactory> */
    use BelongsToTenant, HasFactory, HasUlids;

    protected $fillable = [
        'tenant_id', 'name', 'type', 'min_select', 'max_select', 'free_quantity', 'pricing_rule', 'active',
    ];

    /** @return list<string> */
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
            'type' => OptionGroupType::class,
            'pricing_rule' => FractionPricingRule::class,
            'min_select' => 'integer',
            'max_select' => 'integer',
            'free_quantity' => 'integer',
            'active' => 'boolean',
        ];
    }

    public function isRequired(): bool
    {
        return $this->min_select > 0;
    }

    /** @return HasMany<Option, $this> */
    public function options(): HasMany
    {
        return $this->hasMany(Option::class)->orderBy('sort_order')->orderBy('id');
    }

    protected static function newFactory(): OptionGroupFactory
    {
        return OptionGroupFactory::new();
    }
}
