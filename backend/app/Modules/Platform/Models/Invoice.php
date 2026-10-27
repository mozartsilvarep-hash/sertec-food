<?php

namespace App\Modules\Platform\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Fatura da mensalidade SerTec de um restaurante (um período da assinatura).
 *
 * @property int $id
 * @property string $public_id
 * @property int $tenant_id
 * @property int $subscription_id
 * @property string $plan_name
 * @property int $months
 * @property string $amount
 * @property Carbon $period_start
 * @property Carbon $period_end
 * @property Carbon $due_date
 * @property string $status
 * @property Carbon|null $paid_at
 * @property string|null $paid_via
 * @property string|null $note
 * @property string|null $mp_payment_id
 * @property string|null $pix_code
 * @property string|null $pix_qr_base64
 * @property Carbon|null $pix_expires_at
 * @property int $pix_attempts
 * @property Carbon|null $synced_at
 * @property Carbon|null $reminded_due_at
 * @property Carbon|null $reminded_overdue_at
 * @property-read Subscription $subscription
 */
class Invoice extends Model
{
    use BelongsToTenant, HasUlids;

    public const OPEN = 'open';

    public const PAID = 'paid';

    public const CANCELLED = 'cancelled';

    protected $guarded = ['id', 'tenant_id'];

    protected $hidden = ['id', 'tenant_id', 'subscription_id', 'pix_qr_base64'];

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
        return [
            'amount' => 'decimal:2',
            'months' => 'integer',
            'period_start' => 'date',
            'period_end' => 'date',
            'due_date' => 'date',
            'paid_at' => 'datetime',
            'pix_expires_at' => 'datetime',
            'synced_at' => 'datetime',
            'reminded_due_at' => 'datetime',
            'reminded_overdue_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Subscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function isOverdue(?Carbon $today = null): bool
    {
        return $this->status === self::OPEN && $this->due_date->lt(($today ?? Carbon::today())->startOfDay());
    }

    public function hasValidPix(): bool
    {
        return $this->pix_code !== null && $this->pix_expires_at !== null && $this->pix_expires_at->gt(now()->addMinutes(5));
    }

    /**
     * Referência enviada ao Mercado Pago (conferida no retorno). Leva o
     * restaurante para o webhook entrar no contexto certo sem modo plataforma.
     */
    public function reference(): string
    {
        return 'sertec-fatura:'.$this->tenant()->value('public_id').':'.$this->public_id;
    }

    /**
     * @return array{tenant: string, invoice: string}|null
     */
    public static function parseReference(?string $reference): ?array
    {
        return is_string($reference) && preg_match('/^sertec-fatura:([0-9a-z]{26}):([0-9a-z]{26})$/i', $reference, $m)
            ? ['tenant' => strtolower($m[1]), 'invoice' => strtolower($m[2])] : null;
    }
}
