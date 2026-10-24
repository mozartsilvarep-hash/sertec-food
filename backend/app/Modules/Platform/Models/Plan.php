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
 * @property string $monthly_price
 * @property array<string, bool> $features_json
 * @property bool $active
 */
class Plan extends Model
{
    /** @use HasFactory<PlanFactory> */
    use HasFactory;

    protected $fillable = ['slug', 'name', 'monthly_price', 'features_json', 'active'];

    protected function casts(): array
    {
        return [
            'monthly_price' => 'decimal:2',
            'features_json' => 'array',
            'active' => 'boolean',
        ];
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
