<?php

namespace App\Modules\Ordering\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Tenancy\Models\Branch;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $public_id
 * @property int $tenant_id
 * @property int $branch_id
 * @property string $name
 * @property list<string> $neighborhoods_json
 * @property string $fee
 * @property string|null $minimum_order
 * @property string|null $free_above
 * @property int $eta_minutes
 * @property int $sort_order
 * @property bool $active
 */
class DeliveryZone extends Model
{
    use BelongsToTenant, HasUlids;

    protected $fillable = [
        'tenant_id', 'branch_id', 'name', 'neighborhoods_json', 'fee', 'minimum_order', 'free_above',
        'eta_minutes', 'sort_order', 'active',
    ];

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
            'neighborhoods_json' => 'array',
            'fee' => 'decimal:2',
            'minimum_order' => 'decimal:2',
            'free_above' => 'decimal:2',
            'eta_minutes' => 'integer',
            'sort_order' => 'integer',
            'active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function covers(string $neighborhood): bool
    {
        $needle = self::normalize($neighborhood);

        foreach ($this->neighborhoods_json as $name) {
            if (self::normalize($name) === $needle) {
                return true;
            }
        }

        return false;
    }

    public static function normalize(string $name): string
    {
        $ascii = (string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);

        return (string) preg_replace('/\s+/', ' ', strtolower(trim($ascii)));
    }
}
