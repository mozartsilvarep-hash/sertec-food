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
 * @property Carbon|null $period_end
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

    protected $fillable = ['tenant_id', 'plan_id', 'status', 'period_end'];

    protected function casts(): array
    {
        return ['period_end' => 'datetime'];
    }

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * @param  Builder<Subscription>  $query
     */
    public function scopeCurrent(Builder $query): void
    {
        $query->whereIn('status', [self::STATUS_TRIALING, self::STATUS_ACTIVE])
            ->where(fn (Builder $q) => $q->whereNull('period_end')->orWhere('period_end', '>', now()))
            ->latest('id');
    }

    protected static function newFactory(): SubscriptionFactory
    {
        return SubscriptionFactory::new();
    }
}
