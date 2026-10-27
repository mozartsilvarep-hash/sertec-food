<?php

namespace App\Modules\Cash\Models;

use App\Modules\Cash\Enums\CashMethod;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;

/**
 * Taxa de uma forma de pagamento (maquininha, Pix, site): percentual em
 * pontos-base + valor fixo por venda.
 *
 * @property int $id
 * @property int $tenant_id
 * @property CashMethod $method
 * @property int $percent_bp
 * @property string $fixed_amount
 */
class PaymentFee extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id', 'tenant_id'];

    protected $hidden = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['method' => CashMethod::class, 'percent_bp' => 'integer', 'fixed_amount' => 'decimal:2'];
    }

    public function feeCents(int $amountCents): int
    {
        if ($amountCents <= 0) {
            return 0;
        }
        $fee = intdiv($amountCents * $this->percent_bp + 5000, 10000) + Money::toCents((string) $this->fixed_amount);

        return min($fee, $amountCents);
    }
}
