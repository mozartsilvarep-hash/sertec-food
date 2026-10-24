<?php

namespace App\Modules\Ordering\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $order_id
 * @property string $code subtotal|delivery_fee|discount|total
 * @property string $label
 * @property string $amount
 * @property int $sort_order
 */
class OrderTotal extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'sort_order' => 'integer'];
    }
}
