<?php

namespace App\Modules\Platform\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Notificação recebida da conta Mercado Pago da SerTec (sem tenant: a fatura
 * é encontrada pela external_reference do pagamento consultado na API).
 *
 * @property int $id
 * @property string $external_event_id
 * @property string $resource_id
 * @property bool $signature_valid
 * @property string $status
 */
class BillingEvent extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['signature_valid' => 'boolean'];
    }
}
