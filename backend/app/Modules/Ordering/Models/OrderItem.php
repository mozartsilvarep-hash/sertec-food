<?php

namespace App\Modules\Ordering\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Snapshot imutável do item no momento da compra.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $order_id
 * @property int|null $product_id
 * @property string $product_name_snapshot
 * @property string $variant_name_snapshot
 * @property string $unit_price
 * @property int $quantity
 * @property string $line_total
 * @property string|null $notes
 * @property-read Collection<int, OrderItemOption> $options
 */
class OrderItem extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['unit_price' => 'decimal:2', 'line_total' => 'decimal:2', 'quantity' => 'integer'];
    }

    /** @return HasMany<OrderItemOption, $this> */
    public function options(): HasMany
    {
        return $this->hasMany(OrderItemOption::class)->orderBy('id');
    }
}
