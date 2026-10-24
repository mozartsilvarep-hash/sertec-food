<?php

namespace App\Modules\Catalog\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $public_id
 * @property int $tenant_id
 * @property int $menu_id
 * @property string $name
 * @property string|null $description
 * @property int $sort_order
 * @property bool $active
 * @property-read Menu $menu
 */
class Category extends Model
{
    /** @use HasFactory<CategoryFactory> */
    use BelongsToTenant, HasFactory, HasUlids;

    protected $fillable = ['tenant_id', 'menu_id', 'name', 'description', 'sort_order', 'active'];

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
        return ['active' => 'boolean', 'sort_order' => 'integer'];
    }

    /** @return BelongsTo<Menu, $this> */
    public function menu(): BelongsTo
    {
        return $this->belongsTo(Menu::class);
    }

    /** @return HasMany<MenuProduct, $this> */
    public function placements(): HasMany
    {
        return $this->hasMany(MenuProduct::class)->orderBy('sort_order')->orderBy('id');
    }

    protected static function newFactory(): CategoryFactory
    {
        return CategoryFactory::new();
    }
}
