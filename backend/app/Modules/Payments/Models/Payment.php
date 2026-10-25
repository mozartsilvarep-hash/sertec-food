<?php

namespace App\Modules\Payments\Models;

use App\Modules\Ordering\Enums\PaymentMethod;
use App\Modules\Ordering\Models\Order;
use App\Modules\Payments\Enums\PaymentAttemptStatus;
use App\Modules\Payments\Enums\Provider;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
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
 * @property int $order_id
 * @property Provider $provider
 * @property PaymentMethod $method
 * @property string|null $external_id
 * @property string|null $preference_id
 * @property string $amount
 * @property string $amount_refunded
 * @property PaymentAttemptStatus $status
 * @property string|null $provider_status
 * @property string|null $provider_status_detail
 * @property string|null $pix_qr_code
 * @property string|null $pix_qr_base64
 * @property string|null $ticket_url
 * @property string|null $checkout_url
 * @property string|null $last_error
 * @property Carbon $expires_at
 * @property Carbon|null $approved_at
 * @property Carbon|null $last_synced_at
 * @property Carbon $created_at
 * @property-read Order $order
 * @property-read Collection<int, Refund> $refunds
 */
class Payment extends Model
{
    use BelongsToTenant, HasUlids;

    protected $guarded = ['id', 'tenant_id'];

    protected $hidden = ['id', 'tenant_id', 'order_id'];

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    protected function casts(): array
    {
        return [
            'provider' => Provider::class,
            'method' => PaymentMethod::class,
            'status' => PaymentAttemptStatus::class,
            'amount' => 'decimal:2',
            'amount_refunded' => 'decimal:2',
            'expires_at' => 'datetime',
            'approved_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return HasMany<Refund, $this> */
    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class)->orderBy('id');
    }

    /** O provedor já devolveu os dados para o cliente pagar? */
    public function isStarted(): bool
    {
        return $this->pix_qr_code !== null || $this->checkout_url !== null;
    }
}
