<?php

namespace App\Modules\Catalog\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Tenancy\Models\Branch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Saldo de um produto numa unidade. Existir a linha = produto controlado.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $branch_id
 * @property int $product_id
 * @property int $quantity
 * @property int $min_quantity
 * @property bool $auto_paused
 * @property-read Product|null $product
 * @property-read Branch|null $branch
 */
class ProductStock extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'min_quantity' => 'integer', 'auto_paused' => 'boolean'];
    }

    public function isLow(): bool
    {
        return $this->quantity <= $this->min_quantity;
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
