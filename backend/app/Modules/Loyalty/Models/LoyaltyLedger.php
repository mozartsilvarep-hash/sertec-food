<?php

namespace App\Modules\Loyalty\Models;

use App\Modules\Identity\Models\User;
use App\Modules\Ordering\Models\Order;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $loyalty_card_id
 * @property int|null $order_id
 * @property string $type
 * @property int $stamps_delta
 * @property int $rewards_delta
 * @property string|null $amount
 * @property string|null $note
 * @property int|null $actor_user_id
 * @property Carbon $created_at
 * @property-read Order|null $order
 * @property-read User|null $actor
 */
class LoyaltyLedger extends Model
{
    use BelongsToTenant;

    public const STAMP = 'stamp';

    public const REWARD_USED = 'reward_used';

    public const REWARD_RESTORED = 'reward_restored';

    public const ADJUST = 'adjust';

    public const UPDATED_AT = null;

    protected $table = 'loyalty_ledger';

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime', 'stamps_delta' => 'integer', 'rewards_delta' => 'integer', 'amount' => 'decimal:2'];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
