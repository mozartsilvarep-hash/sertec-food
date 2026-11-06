<?php

namespace App\Modules\Platform\Models;

use App\Modules\Platform\Modules\ModuleKey;
use Illuminate\Database\Eloquent\Model;

/**
 * Preço de um módulo (global, não pertence a tenant). A chave é fixa
 * (ModuleKey); nome e descrição podem ser ajustados no painel SerTec.
 *
 * @property int $id
 * @property string $key
 * @property string $name
 * @property string|null $description
 * @property array<int|string, string|null>|null $prices_json
 * @property bool $active
 * @property int $sort_order
 */
class BillingModule extends Model
{
    protected $fillable = ['key', 'name', 'description', 'prices_json', 'active', 'sort_order'];

    protected function casts(): array
    {
        return ['prices_json' => 'array', 'active' => 'boolean', 'sort_order' => 'integer'];
    }

    public function getRouteKeyName(): string
    {
        return 'key';
    }

    public function moduleKey(): ModuleKey
    {
        return ModuleKey::from($this->key);
    }

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
        foreach (array_keys(Plan::PERIODS) as $m) {
            $out[$m] = $this->priceFor($m);
        }

        return $out;
    }

    /**
     * Catálogo de módulos, na ordem de exibição, indexado pela chave.
     *
     * @return array<string, self>
     */
    public static function catalog(): array
    {
        return self::query()->orderBy('sort_order')->get()->keyBy('key')->all();
    }
}
