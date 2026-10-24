<?php

namespace App\Modules\Catalog\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $public_id
 * @property int $tenant_id
 * @property int $product_id
 * @property string $name
 * @property string $price
 * @property int $sort_order
 * @property bool $active
 * @property-read Product $product
 */
class ProductVariant extends Model
{
    use BelongsToTenant, HasUlids;

    protected $fillable = ['tenant_id', 'product_id', 'name', 'price', 'sort_order', 'active'];

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    protected function casts(): array
    {
        return ['price' => 'decimal:2', 'active' => 'boolean', 'sort_order' => 'integer'];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
