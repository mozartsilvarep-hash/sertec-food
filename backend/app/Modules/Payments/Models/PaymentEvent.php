<?php

namespace App\Modules\Payments\Models;

use App\Modules\Payments\Enums\Provider;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $tenant_id
 * @property Provider $provider
 * @property string $external_event_id
 * @property string $topic
 * @property string|null $action
 * @property string|null $resource_id
 * @property bool|null $live_mode
 * @property bool $signature_valid
 * @property string $status
 * @property int $attempts
 * @property string|null $error
 * @property Carbon|null $processed_at
 */
class PaymentEvent extends Model
{
    use BelongsToTenant;

    public const RECEIVED = 'received';

    public const PROCESSED = 'processed';

    public const IGNORED = 'ignored';

    public const FAILED = 'failed';

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'provider' => Provider::class,
            'live_mode' => 'boolean',
            'signature_valid' => 'boolean',
            'processed_at' => 'datetime',
        ];
    }
}
