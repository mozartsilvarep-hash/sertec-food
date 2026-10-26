<?php

namespace App\Modules\Loyalty\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Cartão de um celular no restaurante: selos acumulados e prêmios para usar.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $phone
 * @property int $stamps
 * @property int $rewards
 */
class LoyaltyCard extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['stamps' => 'integer', 'rewards' => 'integer'];
    }

    /** @return HasMany<LoyaltyLedger, $this> */
    public function ledger(): HasMany
    {
        return $this->hasMany(LoyaltyLedger::class);
    }
}
