<?php

namespace App\Modules\Cash\Models;

use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Tenancy\Models\Branch;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Caixa do dia de uma unidade (abertura → fechamento com conferência).
 *
 * @property int $id
 * @property string $public_id
 * @property int $tenant_id
 * @property int $branch_id
 * @property int|null $opened_by
 * @property Carbon $opened_at
 * @property string $opening_amount
 * @property int|null $closed_by
 * @property Carbon|null $closed_at
 * @property string|null $expected_amount
 * @property string|null $counted_amount
 * @property string|null $notes
 * @property-read Branch $branch
 * @property-read User|null $opener
 * @property-read User|null $closer
 */
class CashSession extends Model
{
    use BelongsToTenant, HasUlids;

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
        return ['opened_at' => 'datetime', 'closed_at' => 'datetime', 'opening_amount' => 'decimal:2', 'expected_amount' => 'decimal:2', 'counted_amount' => 'decimal:2'];
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<User, $this> */
    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    /** @return BelongsTo<User, $this> */
    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    /** @return HasMany<CashEntry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(CashEntry::class);
    }

    public function isOpen(): bool
    {
        return $this->closed_at === null;
    }
}
