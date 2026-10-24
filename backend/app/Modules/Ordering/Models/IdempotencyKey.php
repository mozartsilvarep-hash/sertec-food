<?php

namespace App\Modules\Ordering\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string $key
 * @property string $request_hash
 * @property int $status_code
 * @property array<string, mixed> $response_json
 */
class IdempotencyKey extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $fillable = ['tenant_id', 'key', 'request_hash', 'status_code', 'response_json'];

    protected function casts(): array
    {
        return ['response_json' => 'array', 'status_code' => 'integer'];
    }
}
