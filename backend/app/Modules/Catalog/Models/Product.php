<?php

namespace App\Modules\Catalog\Models;

use App\Modules\Catalog\Support\ProductSchedule;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property string $public_id
 * @property int $tenant_id
 * @property string $name
 * @property string|null $description
 * @property string|null $code
 * @property bool $pos_favorite
 * @property string|null $image_key
 * @property bool $active
 * @property string $kind item | combo
 * @property list<int>|null $available_days 0 = domingo … 6 = sábado; null = todos
 * @property string|null $available_from HH:MM:SS
 * @property string|null $available_until HH:MM:SS
 * @property-read Collection<int, ProductVariant> $variants
 * @property-read Collection<int, OptionGroup> $optionGroups
 * @property-read Collection<int, MenuProduct> $placements
 */
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use BelongsToTenant, HasFactory, HasUlids, SoftDeletes;

    protected $fillable = ['tenant_id', 'name', 'description', 'active', 'kind', 'available_days', 'available_from', 'available_until', 'pos_favorite'];

    public const KIND_ITEM = 'item';

    public const KIND_COMBO = 'combo';

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
        return ['active' => 'boolean', 'available_days' => 'array', 'pos_favorite' => 'boolean'];
    }

    /** @return HasMany<ProductVariant, $this> */
    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * Somente leitura: gravação por ProductOptionGroup (BelongsToTenant).
     *
     * @return BelongsToMany<OptionGroup, $this>
     */
    public function optionGroups(): BelongsToMany
    {
        return $this->belongsToMany(OptionGroup::class, 'product_option_groups')
            ->withPivot('sort_order')
            ->orderByPivot('sort_order')
            ->orderBy('option_groups.id');
    }

    /** @return HasMany<MenuProduct, $this> */
    public function placements(): HasMany
    {
        return $this->hasMany(MenuProduct::class);
    }

    protected static function newFactory(): ProductFactory
    {
        return ProductFactory::new();
    }

    public function isCombo(): bool
    {
        return $this->kind === self::KIND_COMBO;
    }

    public function schedule(): ProductSchedule
    {
        return ProductSchedule::for($this);
    }
}
