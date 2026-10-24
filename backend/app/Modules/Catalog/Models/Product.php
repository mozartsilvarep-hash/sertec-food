<?php

namespace App\Modules\Catalog\Models;

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
 * @property string|null $image_key
 * @property bool $active
 * @property-read Collection<int, ProductVariant> $variants
 * @property-read Collection<int, OptionGroup> $optionGroups
 * @property-read Collection<int, MenuProduct> $placements
 */
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use BelongsToTenant, HasFactory, HasUlids, SoftDeletes;

    protected $fillable = ['tenant_id', 'name', 'description', 'active'];

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
        return ['active' => 'boolean'];
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
}
