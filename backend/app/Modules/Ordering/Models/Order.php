<?php

namespace App\Modules\Ordering\Models;

use App\Modules\Ordering\Enums\FulfillmentType;
use App\Modules\Ordering\Enums\OrderStatus;
use App\Modules\Ordering\Enums\PaymentMethod;
use App\Modules\Ordering\Enums\PaymentStatus;
use App\Modules\Payments\Models\Payment;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Tenancy\Models\Branch;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $public_id
 * @property int $tenant_id
 * @property int $branch_id
 * @property int|null $customer_id
 * @property int $order_number
 * @property string $tracking_token_hash
 * @property OrderStatus $status
 * @property PaymentStatus $payment_status
 * @property PaymentMethod $payment_method
 * @property string|null $change_for
 * @property FulfillmentType $fulfillment_type
 * @property string $customer_name
 * @property string $customer_phone
 * @property array<string, string|null>|null $address_snapshot_json
 * @property string|null $delivery_zone_name
 * @property string|null $notes
 * @property string $subtotal
 * @property string $discount_total
 * @property string $delivery_fee
 * @property string $total
 * @property int|null $eta_minutes
 * @property string|null $cancellation_reason
 * @property string|null $cancellation_note
 * @property Carbon $placed_at
 * @property Carbon|null $accepted_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $cancelled_at
 * @property-read Branch $branch
 * @property-read Collection<int, OrderItem> $items
 * @property-read Collection<int, OrderTotal> $totals
 * @property-read Collection<int, OrderStatusHistory> $history
 * @property-read Collection<int, Payment> $payments
 */
class Order extends Model
{
    use BelongsToTenant, HasUlids;

    protected $guarded = ['id', 'tenant_id'];

    protected $hidden = ['id', 'tenant_id', 'tracking_token_hash'];

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
            'status' => OrderStatus::class,
            'payment_status' => PaymentStatus::class,
            'payment_method' => PaymentMethod::class,
            'fulfillment_type' => FulfillmentType::class,
            'address_snapshot_json' => 'array',
            'change_for' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'delivery_fee' => 'decimal:2',
            'total' => 'decimal:2',
            'placed_at' => 'datetime',
            'accepted_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return HasMany<OrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class)->orderBy('id');
    }

    /** @return HasMany<OrderTotal, $this> */
    public function totals(): HasMany
    {
        return $this->hasMany(OrderTotal::class)->orderBy('sort_order');
    }

    /** @return HasMany<OrderStatusHistory, $this> */
    public function history(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class)->orderBy('id');
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->orderBy('id');
    }
}
