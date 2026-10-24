<?php

namespace App\Modules\Tenancy\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Data especial (feriado, evento). Se existir ao menos uma linha para a data,
 * ela substitui o horário semanal daquela data.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $branch_id
 * @property Carbon $date
 * @property bool $closed
 * @property string|null $opens_at
 * @property string|null $closes_at
 * @property string|null $note
 */
class BranchScheduleException extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'branch_id', 'date', 'closed', 'opens_at', 'closes_at', 'note'];

    protected function casts(): array
    {
        return ['date' => 'date', 'closed' => 'boolean'];
    }
}
