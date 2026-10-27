<?php

namespace App\Modules\Cash\Models;

use App\Modules\Cash\Enums\CashCategory;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Tenancy\Models\Branch;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Custo fixo mensal (aluguel, internet, salários…). Entra no resultado do
 * período proporcional aos dias; a saída do caixa ligada a ele não conta
 * de novo.
 *
 * @property int $id
 * @property string $public_id
 * @property int $tenant_id
 * @property int|null $branch_id
 * @property string $name
 * @property CashCategory $category
 * @property string $amount
 * @property int|null $due_day
 * @property bool $active
 * @property-read Branch|null $branch
 */
class FixedCost extends Model
{
    use BelongsToTenant, HasUlids;

    protected $guarded = ['id', 'tenant_id'];

    protected $hidden = ['id', 'tenant_id', 'branch_id'];

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
        return ['category' => CashCategory::class, 'amount' => 'decimal:2', 'due_day' => 'integer', 'active' => 'boolean'];
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
