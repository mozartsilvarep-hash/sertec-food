<?php

namespace App\Modules\Tenancy\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Período semanal de funcionamento (fuso do tenant). closes_at <= opens_at
 * significa que o período termina no dia seguinte (ex.: 18:00–02:00).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $branch_id
 * @property int $weekday 0=domingo … 6=sábado
 * @property string $opens_at HH:MM:SS
 * @property string $closes_at HH:MM:SS
 */
class BranchOpeningHour extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'branch_id', 'weekday', 'opens_at', 'closes_at'];

    protected function casts(): array
    {
        return ['weekday' => 'integer'];
    }
}
