<?php

namespace App\Modules\Ordering\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $order_item_id
 * @property string $group_name_snapshot
 * @property string $option_name_snapshot
 * @property int $quantity
 * @property int $charged_quantity
 * @property string $unit_price
 * @property string $total
 */
class OrderItemOption extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['unit_price' => 'decimal:2', 'total' => 'decimal:2', 'quantity' => 'integer', 'charged_quantity' => 'integer'];
    }
}
