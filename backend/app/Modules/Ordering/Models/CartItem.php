<?php

namespace App\Modules\Ordering\Models;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $public_id
 * @property int $tenant_id
 * @property int $cart_id
 * @property int $product_id
 * @property int $product_variant_id
 * @property int $quantity
 * @property list<array<string, mixed>> $selections_json
 * @property string|null $notes
 * @property-read Product|null $product
 * @property-read ProductVariant|null $variant
 */
class CartItem extends Model
{
    use BelongsToTenant, HasUlids;

    public const MAX_QUANTITY = 50;

    protected $fillable = ['tenant_id', 'cart_id', 'product_id', 'product_variant_id', 'quantity', 'selections_json', 'notes'];

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    protected function casts(): array
    {
        return ['selections_json' => 'array', 'quantity' => 'integer'];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
