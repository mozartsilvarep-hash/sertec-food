<?php

namespace App\Modules\Cash\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Tenancy\Models\Branch;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Mesa cadastrada (para o QR Code). A conta aberta dela é a Tab com o mesmo
 * nome (Tab::keyFor), na mesma unidade.
 *
 * @property int $id
 * @property string $public_id
 * @property int $tenant_id
 * @property int $branch_id
 * @property string $label
 * @property string $qr_token
 * @property bool $active
 * @property int $sort_order
 * @property-read Branch|null $branch
 */
class DiningTable extends Model
{
    use BelongsToTenant, HasUlids;

    protected $guarded = ['id', 'tenant_id'];

    protected $hidden = ['id', 'tenant_id', 'branch_id'];

    /**
     * @return list<string>
     */
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
        return ['active' => 'boolean', 'sort_order' => 'integer'];
    }

    public static function newToken(): string
    {
        return Str::lower(Str::random(24));
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
