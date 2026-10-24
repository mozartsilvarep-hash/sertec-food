<?php

namespace App\Modules\Platform\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Trilha de auditoria somente-inserção.
 *
 * Exceção documentada ao BelongsToTenant: tenant_id é NULL para ações de
 * plataforma. Leitura é restrita ao painel SerTec (modo plataforma) e, em
 * sprints futuras, ao próprio tenant via consulta explícita por tenant_id.
 *
 * @property int $id
 * @property int|null $tenant_id
 * @property int|null $actor_user_id
 * @property string $action
 * @property string|null $entity_type
 * @property string|null $entity_id
 * @property array<string, mixed>|null $metadata_json
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id', 'actor_user_id', 'action', 'entity_type', 'entity_id',
        'metadata_json', 'ip', 'request_id',
    ];

    protected function casts(): array
    {
        return [
            'metadata_json' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Audit logs are append-only.'));
        static::deleting(fn () => throw new LogicException('Audit logs are append-only.'));
    }
}
