<?php

namespace App\Modules\Platform\Models;

use App\Modules\Platform\Modules\ModuleKey;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use App\Support\Money;
use Database\Factories\SubscriptionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $plan_id
 * @property list<string>|null $modules_json
 * @property string $status
 * @property int $billing_months
 * @property string|null $price
 * @property Carbon|null $period_end
 * @property Carbon|null $paid_until
 * @property-read Plan $plan
 */
class Subscription extends Model
{
    /** @use HasFactory<SubscriptionFactory> */
    use BelongsToTenant, HasFactory;

    public const STATUS_TRIALING = 'trialing';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_PAST_DUE = 'past_due';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = ['tenant_id', 'plan_id', 'modules_json', 'status', 'period_end', 'billing_months', 'price', 'paid_until'];

    protected function casts(): array
    {
        return ['period_end' => 'datetime', 'paid_until' => 'date', 'price' => 'decimal:2', 'billing_months' => 'integer', 'modules_json' => 'array'];
    }

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * Módulos contratados (sem lista = só o Cardápio digital). Mesas e
     * comandas sem o PDV não vale.
     *
     * @return list<ModuleKey>
     */
    public function modules(): array
    {
        $list = $this->modules_json === null ? [ModuleKey::Menu] : ModuleKey::list($this->modules_json);

        return array_values(array_filter($list, fn (ModuleKey $m) => $m->requires() === null || in_array($m->requires(), $list, true)));
    }

    public function hasModule(ModuleKey $module): bool
    {
        return in_array($module, $this->modules(), true);
    }

    /**
     * Composição do valor do período: plano (se tiver preço) + cada módulo.
     *
     * @param  array<string, BillingModule>|null  $catalog
     * @return list<array{label: string, amount: string|null, module: string|null}>
     */
    public function priceLines(?array $catalog = null): array
    {
        $catalog ??= BillingModule::catalog();
        $lines = [];
        $planPrice = $this->plan->priceFor($this->billing_months);
        if ($planPrice !== null && Money::toCents($planPrice) > 0) {
            $lines[] = ['label' => $this->plan->name, 'amount' => $planPrice, 'module' => null];
        }
        foreach ($this->modules() as $m) {
            $row = $catalog[$m->value] ?? null;
            $lines[] = ['label' => $row->name ?? $m->label(), 'amount' => $row?->priceFor($this->billing_months), 'module' => $m->value];
        }

        return $lines;
    }

    /**
     * Soma do catálogo (null = nada com preço neste período).
     *
     * @param  array<string, BillingModule>|null  $catalog
     */
    public function catalogPrice(?array $catalog = null): ?string
    {
        $priced = array_filter($this->priceLines($catalog), fn (array $l) => $l['amount'] !== null);
        if ($priced === []) {
            return null;
        }

        return Money::format(array_sum(array_map(fn (array $l) => Money::toCents((string) $l['amount']), $priced)));
    }

    /** Valor a cobrar por período; null = sem preço definido; "0.00" = cortesia. */
    public function effectivePrice(): ?string
    {
        if ($this->price !== null) {
            return number_format((float) $this->price, 2, '.', '');
        }

        return $this->catalogPrice();
    }

    /** Nome na fatura: "Cardápio digital + PDV e caixa". */
    public function billingLabel(): string
    {
        $names = array_map(fn (array $l) => $l['label'], $this->priceLines());

        return mb_substr($names === [] ? $this->plan->name : implode(' + ', $names), 0, 120);
    }

    /**
     * @param  Builder<Subscription>  $query
     */
    public function scopeCurrent(Builder $query): void
    {
        // Mensalidade atrasada não corta o sistema sozinha: a SerTec suspende
        // o estabelecimento manualmente se quiser (D83).
        $query->whereIn('status', [self::STATUS_TRIALING, self::STATUS_ACTIVE, self::STATUS_PAST_DUE])
            ->where(fn (Builder $q) => $q->whereNull('period_end')->orWhere('period_end', '>', now()))
            ->latest('id');
    }

    protected static function newFactory(): SubscriptionFactory
    {
        return SubscriptionFactory::new();
    }
}
