<?php

namespace App\Modules\Cash\Models;

use App\Modules\Identity\Models\User;
use App\Modules\Ordering\Models\Order;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Tenancy\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Mesa/comanda aberta: recebe itens e, ao fechar, vira venda do PDV.
 *
 * @property int $id
 * @property string $public_id
 * @property int $tenant_id
 * @property int $branch_id
 * @property string $label
 * @property string|null $open_key
 * @property string|null $customer_name
 * @property int $people
 * @property string $status
 * @property int $kitchen_batches
 * @property CarbonImmutable|null $bill_requested_at
 * @property string|null $notes
 * @property int|null $opened_by
 * @property int|null $closed_by
 * @property int|null $order_id
 * @property CarbonImmutable $opened_at
 * @property CarbonImmutable|null $closed_at
 * @property-read Branch|null $branch
 * @property-read Order|null $order
 * @property-read User|null $opener
 * @property-read Collection<int, TabItem> $items
 */
class Tab extends Model
{
    use BelongsToTenant, HasUlids;

    public const OPEN = 'open';

    public const CLOSED = 'closed';

    public const CANCELLED = 'cancelled';

    protected $guarded = ['id', 'tenant_id'];

    protected $hidden = ['id', 'tenant_id', 'branch_id', 'order_id', 'opened_by', 'closed_by'];

    /**
     * @return list<string>
     */
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
        return ['people' => 'integer', 'kitchen_batches' => 'integer', 'opened_at' => 'immutable_datetime', 'closed_at' => 'immutable_datetime', 'bill_requested_at' => 'immutable_datetime'];
    }

    public function isOpen(): bool
    {
        return $this->status === self::OPEN;
    }

    public static function keyFor(string $label): string
    {
        return mb_substr(mb_strtolower(preg_replace('/\s+/', ' ', trim($label)) ?? ''), 0, 40);
    }

    /** @return HasMany<TabItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(TabItem::class)->orderBy('id');
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<User, $this> */
    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }
}
