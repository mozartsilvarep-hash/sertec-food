<?php

namespace App\Modules\Ordering\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $public_id
 * @property int $tenant_id
 * @property string $name
 * @property string $phone
 * @property string|null $email
 * @property Carbon|null $last_order_at
 */
class Customer extends Model
{
    use BelongsToTenant, HasUlids;

    protected $fillable = ['tenant_id', 'name', 'phone', 'email', 'last_order_at'];

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    protected function casts(): array
    {
        return ['last_order_at' => 'datetime'];
    }
}
