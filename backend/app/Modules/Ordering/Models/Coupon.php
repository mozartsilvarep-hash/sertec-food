<?php

namespace App\Modules\Ordering\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $public_id
 * @property int $tenant_id
 * @property string $code
 * @property string|null $description
 * @property string $discount_type percent|fixed|free_delivery
 * @property int|null $percent_bp
 * @property string|null $amount
 * @property string|null $max_discount
 * @property string|null $min_subtotal
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property int|null $usage_limit
 * @property int|null $per_customer_limit
 * @property bool $first_order_only
 * @property int $times_used
 * @property bool $active
 */
class Coupon extends Model
{
    use BelongsToTenant, HasUlids;

    public const PERCENT = 'percent';

    public const FIXED = 'fixed';

    public const FREE_DELIVERY = 'free_delivery';

    protected $guarded = ['id', 'tenant_id', 'times_used'];

    protected $hidden = ['id', 'tenant_id'];

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
        return [
            'percent_bp' => 'integer',
            'amount' => 'decimal:2',
            'max_discount' => 'decimal:2',
            'min_subtotal' => 'decimal:2',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'usage_limit' => 'integer',
            'per_customer_limit' => 'integer',
            'first_order_only' => 'boolean',
            'times_used' => 'integer',
            'active' => 'boolean',
        ];
    }

    public static function normalize(string $code): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9_-]/', '', trim($code)) ?? '');
    }
}
