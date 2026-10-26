<?php

namespace App\Modules\Catalog\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Promoção de um produto num período. Uma promoção vigente por produto
 * (sobreposição recusada ao salvar). Dois tipos:
 * - percent: desconto percentual no preço da unidade;
 * - bundle: "leve N, pague M" (ex.: compre 1, leve outro = leve 2, pague 1),
 *   aplicado no carrinho sobre as unidades mais baratas do produto.
 *
 * @property int $id
 * @property string $public_id
 * @property int $tenant_id
 * @property int $product_id
 * @property string $type percent | bundle
 * @property int $percent_bp
 * @property int|null $buy_quantity leve N (bundle)
 * @property int|null $pay_quantity pague M (bundle)
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property bool $featured
 * @property string|null $badge
 * @property bool $active
 * @property-read Product $product
 */
class Promotion extends Model
{
    use BelongsToTenant, HasUlids;

    protected $guarded = ['id', 'tenant_id'];

    protected $hidden = ['id', 'tenant_id', 'product_id'];

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
            'percent_bp' => 'integer',
            'buy_quantity' => 'integer',
            'pay_quantity' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'featured' => 'boolean',
            'active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    /**
     * @param  Builder<Promotion>  $query
     * @return Builder<Promotion>
     */
    public function scopeRunning(Builder $query, ?\DateTimeInterface $at = null): Builder
    {
        $at ??= now();

        return $query->where('active', true)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $at))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $at));
    }

    public const TYPE_PERCENT = 'percent';

    public const TYPE_BUNDLE = 'bundle';

    public function isBundle(): bool
    {
        return $this->type === self::TYPE_BUNDLE;
    }

    /**
     * Unidades grátis em "leve N, pague M" para uma quantidade total.
     */
    public function freeUnits(int $quantity): int
    {
        if (! $this->isBundle() || ! $this->buy_quantity || $this->pay_quantity === null || $this->pay_quantity >= $this->buy_quantity) {
            return 0;
        }

        return intdiv($quantity, $this->buy_quantity) * ($this->buy_quantity - $this->pay_quantity);
    }

    /** Aplica o desconto a um valor em centavos (arredonda ao centavo mais próximo). */
    public static function apply(int $cents, int $percentBp): int
    {
        return intdiv($cents * (10000 - $percentBp) + 5000, 10000);
    }

    /** Selo padrão: "-17%" ou "Leve 2, pague 1". */
    public function label(): string
    {
        return $this->badge ?: $this->defaultLabel();
    }

    public function defaultLabel(): string
    {
        if ($this->isBundle()) {
            return "Leve {$this->buy_quantity}, pague {$this->pay_quantity}";
        }

        return '-'.rtrim(rtrim(number_format($this->percent_bp / 100, 2, ',', ''), '0'), ',').'%';
    }
}
