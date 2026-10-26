<?php

namespace App\Modules\Ordering\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $coupon_id
 * @property int $order_id
 * @property string $customer_phone
 * @property string $amount
 * @property string $status applied|voided
 * @property-read Coupon $coupon
 */
class CouponRedemption extends Model
{
    use BelongsToTenant;

    public const APPLIED = 'applied';

    public const VOIDED = 'voided';

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    /** @return BelongsTo<Coupon, $this> */
    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }
}
