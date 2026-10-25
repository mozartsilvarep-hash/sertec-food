<?php

namespace App\Modules\Ordering\Models;

use App\Modules\Ordering\Enums\OrderStatus;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Histórico somente-inserção das mudanças de estado.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $order_id
 * @property OrderStatus|null $old_status
 * @property OrderStatus $new_status
 * @property int|null $actor_user_id
 * @property string|null $note
 * @property Carbon $created_at
 */
class OrderStatusHistory extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $table = 'order_status_history';

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['old_status' => OrderStatus::class, 'new_status' => OrderStatus::class, 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Order history is append-only.'));
        static::deleting(fn () => throw new LogicException('Order history is append-only.'));
    }
}
