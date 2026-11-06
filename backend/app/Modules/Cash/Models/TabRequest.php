<?php

namespace App\Modules\Cash\Models;

use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pedido/chamado feito pelo QR Code da mesa. Só vira item da conta quando
 * o garçom confirma (D103).
 *
 * @property int $id
 * @property string $public_id
 * @property int $tenant_id
 * @property int $branch_id
 * @property int $dining_table_id
 * @property int|null $tab_id
 * @property string $type
 * @property list<array<string, mixed>>|null $lines_json
 * @property string|null $summary
 * @property string|null $customer_name
 * @property string|null $notes
 * @property string $status
 * @property string $view_token_hash
 * @property int|null $decided_by
 * @property CarbonImmutable|null $decided_at
 * @property string|null $reason
 * @property CarbonImmutable $created_at
 * @property-read DiningTable|null $table
 * @property-read User|null $decider
 */
class TabRequest extends Model
{
    use BelongsToTenant, HasUlids;

    public const ORDER = 'order';

    public const WAITER = 'waiter';

    public const BILL = 'bill';

    public const PENDING = 'pending';

    public const ACCEPTED = 'accepted';

    public const REJECTED = 'rejected';

    public const EXPIRED = 'expired';

    /** Minutos até um pedido não confirmado expirar. */
    public const TTL_MINUTES = 30;

    protected $guarded = ['id', 'tenant_id'];

    protected $hidden = ['id', 'tenant_id', 'branch_id', 'dining_table_id', 'tab_id', 'view_token_hash'];

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
        return ['lines_json' => 'array', 'decided_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<DiningTable, $this> */
    public function table(): BelongsTo
    {
        return $this->belongsTo(DiningTable::class, 'dining_table_id');
    }

    /** @return BelongsTo<User, $this> */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
