<?php

namespace App\Modules\Catalog\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Desconto percentual de um produto num período. Uma promoção vigente por
 * produto (sobreposição recusada ao salvar).
 *
 * @property int $id
 * @property string $public_id
 * @property int $tenant_id
 * @property int $product_id
 * @property int $percent_bp
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

    /** Aplica o desconto a um valor em centavos (arredonda ao centavo mais próximo). */
    public static function apply(int $cents, int $percentBp): int
    {
        return intdiv($cents * (10000 - $percentBp) + 5000, 10000);
    }

    /** Selo padrão: "-17%". */
    public function label(): string
    {
        return $this->badge ?: '-'.rtrim(rtrim(number_format($this->percent_bp / 100, 2, ',', ''), '0'), ',').'%';
    }
}
