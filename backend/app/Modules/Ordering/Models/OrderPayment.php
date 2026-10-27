<?php

namespace App\Modules\Ordering\Models;

use App\Modules\Cash\Enums\CashMethod;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Parte do pagamento de uma venda do PDV (ex.: R$ 30 no Pix + R$ 20 em dinheiro).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $order_id
 * @property CashMethod $method
 * @property string $amount
 */
class OrderPayment extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id', 'tenant_id'];

    protected $hidden = ['id', 'tenant_id', 'order_id'];

    protected function casts(): array
    {
        return ['method' => CashMethod::class, 'amount' => 'decimal:2'];
    }
}
