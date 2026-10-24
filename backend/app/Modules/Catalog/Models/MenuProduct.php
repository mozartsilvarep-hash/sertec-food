<?php

namespace App\Modules\Catalog\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Produto posicionado numa categoria. Gravado sempre por este model
 * (nunca via attach() de belongsToMany) para passar pelo BelongsToTenant.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $category_id
 * @property int $product_id
 * @property int $sort_order
 * @property-read Product|null $product nulo se o produto foi arquivado ou filtrado no eager load
 * @property-read Category $category
 */
class MenuProduct extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'category_id', 'product_id', 'sort_order'];

    protected function casts(): array
    {
        return ['sort_order' => 'integer'];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
