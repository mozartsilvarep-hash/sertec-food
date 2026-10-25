<?php

namespace App\Modules\Ordering\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $branch_id
 * @property int $last_number
 */
class OrderSequence extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'branch_id', 'last_number'];
}
