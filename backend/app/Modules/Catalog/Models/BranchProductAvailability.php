<?php

namespace App\Modules\Catalog\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $branch_id
 * @property int $product_id
 * @property bool $available
 */
class BranchProductAvailability extends Model
{
    use BelongsToTenant;

    protected $table = 'branch_product_availability';

    protected $fillable = ['tenant_id', 'branch_id', 'product_id', 'available'];

    protected function casts(): array
    {
        return ['available' => 'boolean'];
    }
}
