<?php

namespace App\Modules\Loyalty\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;

/**
 * Regra do cartão fidelidade do restaurante (um por restaurante).
 *
 * @property int $id
 * @property int $tenant_id
 * @property bool $active
 * @property int $stamps_required
 * @property string|null $min_order
 * @property string $reward_type fixed | percent
 * @property string|null $reward_amount
 * @property int|null $reward_percent_bp
 * @property string|null $reward_max
 */
class LoyaltyProgram extends Model
{
    use BelongsToTenant;

    public const FIXED = 'fixed';

    public const PERCENT = 'percent';

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'stamps_required' => 'integer',
            'min_order' => 'decimal:2',
            'reward_amount' => 'decimal:2',
            'reward_percent_bp' => 'integer',
            'reward_max' => 'decimal:2',
        ];
    }

    /** "R$ 20,00 de desconto" / "15% de desconto (até R$ 30,00)". */
    public function rewardLabel(): string
    {
        $brl = fn (?string $v) => 'R$ '.number_format((float) $v, 2, ',', '.');
        if ($this->reward_type === self::PERCENT) {
            $pct = rtrim(rtrim(number_format(($this->reward_percent_bp ?? 0) / 100, 2, ',', ''), '0'), ',');

            return "{$pct}% de desconto".($this->reward_max ? ' (até '.$brl($this->reward_max).')' : '');
        }

        return $brl($this->reward_amount).' de desconto';
    }

    /** Desconto do prêmio sobre o valor dos itens (em centavos). */
    public function rewardCents(int $itemsCents): int
    {
        if ($this->reward_type === self::PERCENT) {
            $value = intdiv($itemsCents * ($this->reward_percent_bp ?? 0) + 5000, 10000);
            if ($this->reward_max !== null) {
                $value = min($value, Money::toCents((string) $this->reward_max));
            }
        } else {
            $value = Money::toCents((string) $this->reward_amount);
        }

        return max(0, min($value, $itemsCents));
    }

    public function minOrderCents(): int
    {
        return $this->min_order === null ? 0 : Money::toCents((string) $this->min_order);
    }
}
