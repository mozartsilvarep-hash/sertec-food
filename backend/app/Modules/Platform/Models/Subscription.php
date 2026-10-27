<?php

namespace App\Modules\Platform\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Database\Factories\SubscriptionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $plan_id
 * @property string $status
 * @property int $billing_months
 * @property string|null $price
 * @property Carbon|null $period_end
 * @property Carbon|null $paid_until
 * @property-read Plan $plan
 */
class Subscription extends Model
{
    /** @use HasFactory<SubscriptionFactory> */
    use BelongsToTenant, HasFactory;

    public const STATUS_TRIALING = 'trialing';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_PAST_DUE = 'past_due';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = ['tenant_id', 'plan_id', 'status', 'period_end', 'billing_months', 'price', 'paid_until'];

    protected function casts(): array
    {
        return ['period_end' => 'datetime', 'paid_until' => 'date', 'price' => 'decimal:2', 'billing_months' => 'integer'];
    }

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /** Valor a cobrar por período; null = sem preço definido; "0.00" = cortesia. */
    public function effectivePrice(): ?string
    {
        if ($this->price !== null) {
            return number_format((float) $this->price, 2, '.', '');
        }

        return $this->plan->priceFor($this->billing_months);
    }

    /**
     * @param  Builder<Subscription>  $query
     */
    public function scopeCurrent(Builder $query): void
    {
        // Mensalidade atrasada não corta o sistema sozinha: a SerTec suspende
        // o estabelecimento manualmente se quiser (D83).
        $query->whereIn('status', [self::STATUS_TRIALING, self::STATUS_ACTIVE, self::STATUS_PAST_DUE])
            ->where(fn (Builder $q) => $q->whereNull('period_end')->orWhere('period_end', '>', now()))
            ->latest('id');
    }

    protected static function newFactory(): SubscriptionFactory
    {
        return SubscriptionFactory::new();
    }
}
