<?php

namespace App\Modules\Platform\Models;

use Database\Factories\PlanFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Plano comercial (global, não pertence a tenant).
 *
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property string|null $description
 * @property string $monthly_price
 * @property array<int|string, string|null>|null $prices_json
 * @property array<string, bool> $features_json
 * @property bool $active
 */
class Plan extends Model
{
    /** @use HasFactory<PlanFactory> */
    use HasFactory;

    /** Períodos de cobrança aceitos (meses). */
    public const PERIODS = [1 => 'Mensal', 3 => 'Trimestral', 6 => 'Semestral', 12 => 'Anual'];

    protected $fillable = ['slug', 'name', 'description', 'monthly_price', 'prices_json', 'features_json', 'active'];

    protected function casts(): array
    {
        return [
            'monthly_price' => 'decimal:2',
            'features_json' => 'array',
            'prices_json' => 'array',
            'active' => 'boolean',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /** Preço do período (ex.: "279.00" para 3 meses) ou null se o plano não oferece. */
    public function priceFor(int $months): ?string
    {
        $price = ($this->prices_json ?? [])[$months] ?? null;

        return $price === null || $price === '' ? null : number_format((float) $price, 2, '.', '');
    }

    /**
     * @return array<int, string|null>
     */
    public function prices(): array
    {
        $out = [];
        foreach (array_keys(self::PERIODS) as $m) {
            $out[$m] = $this->priceFor($m);
        }

        return $out;
    }

    public function includes(string $feature): bool
    {
        return ($this->features_json[$feature] ?? false) === true;
    }

    protected static function newFactory(): PlanFactory
    {
        return PlanFactory::new();
    }
}
