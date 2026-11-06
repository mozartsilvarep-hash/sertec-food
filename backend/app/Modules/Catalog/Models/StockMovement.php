<?php

namespace App\Modules\Catalog\Models;

use App\Modules\Catalog\Stock\StockMovementType;
use App\Modules\Identity\Models\User;
use App\Modules\Ordering\Models\Order;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $branch_id
 * @property int $product_id
 * @property StockMovementType $type
 * @property int $quantity
 * @property int $balance_after
 * @property int|null $order_id
 * @property int|null $tab_item_id
 * @property int|null $user_id
 * @property string|null $reason
 * @property CarbonImmutable $occurred_at
 * @property-read User|null $user
 * @property-read Order|null $order
 * @property-read Product|null $product
 */
class StockMovement extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['type' => StockMovementType::class, 'quantity' => 'integer', 'balance_after' => 'integer', 'occurred_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }
}
