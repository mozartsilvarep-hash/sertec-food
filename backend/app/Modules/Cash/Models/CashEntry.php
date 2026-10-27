<?php

namespace App\Modules\Cash\Models;

use App\Modules\Cash\Enums\CashCategory;
use App\Modules\Cash\Enums\CashMethod;
use App\Modules\Identity\Models\User;
use App\Modules\Ordering\Models\Order;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Tenancy\Models\Branch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Lançamento do livro-caixa. Valor sempre positivo; "type" diz se entra ou sai.
 *
 * @property int $id
 * @property string $public_id
 * @property int $tenant_id
 * @property int $branch_id
 * @property int|null $cash_session_id
 * @property string $type in | out
 * @property CashCategory $category
 * @property CashMethod $method
 * @property string $amount
 * @property string $fee_amount
 * @property int|null $fixed_cost_id
 * @property string|null $description
 * @property list<array{name: string, quantity: int, unit_price: string, total: string}>|null $items_json
 * @property int|null $order_id
 * @property string|null $source
 * @property Carbon $occurred_at
 * @property int|null $created_by
 * @property Carbon|null $voided_at
 * @property int|null $voided_by
 * @property string|null $void_reason
 * @property-read Branch $branch
 * @property-read User|null $creator
 * @property-read Order|null $order
 */
class CashEntry extends Model
{
    use BelongsToTenant, HasUlids;

    public const IN = 'in';

    public const OUT = 'out';

    protected $guarded = ['id', 'tenant_id'];

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
            'category' => CashCategory::class,
            'method' => CashMethod::class,
            'amount' => 'decimal:2',
            'items_json' => 'array',
            'occurred_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @param  Builder<CashEntry>  $query
     * @return Builder<CashEntry>
     */
    public function scopeValid(Builder $query): Builder
    {
        return $query->whereNull('voided_at');
    }
}
