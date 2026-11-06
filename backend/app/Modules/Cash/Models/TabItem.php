<?php

namespace App\Modules\Cash\Models;

use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Item lançado na comanda. Preço calculado pelo servidor na hora do
 * lançamento (mesma calculadora do cardápio); avulso usa o valor do caixa.
 *
 * @property int $id
 * @property string $public_id
 * @property int $tenant_id
 * @property int $tab_id
 * @property int|null $product_id
 * @property int|null $variant_id
 * @property array{selections?: list<array<string, mixed>>, lines?: list<array<string, mixed>>, variant_name?: string}|null $options_json
 * @property string $name
 * @property string|null $detail
 * @property string $unit_price
 * @property int $quantity
 * @property string|null $notes
 * @property int|null $added_by
 * @property int|null $kitchen_batch
 * @property CarbonImmutable|null $sent_at
 * @property CarbonImmutable|null $voided_at
 * @property int|null $voided_by
 * @property string|null $void_reason
 * @property-read User|null $adder
 */
class TabItem extends Model
{
    use BelongsToTenant, HasUlids;

    protected $guarded = ['id', 'tenant_id'];

    protected $hidden = ['id', 'tenant_id', 'tab_id', 'product_id', 'variant_id'];

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
        return [
            'options_json' => 'array', 'unit_price' => 'decimal:2', 'quantity' => 'integer', 'kitchen_batch' => 'integer',
            'sent_at' => 'immutable_datetime', 'voided_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function adder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by');
    }
}
