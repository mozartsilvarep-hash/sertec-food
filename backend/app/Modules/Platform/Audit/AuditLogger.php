<?php

namespace App\Modules\Platform\Audit;

use App\Http\Middleware\AssignRequestId;
use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\AuditLog;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;

/**
 * Registra ações sensíveis. Nunca grave segredos, senhas, tokens ou payloads
 * completos em metadata (SECURITY.md).
 *
 * Sem estado: resolve contexto e requisição no momento do registro, então é
 * seguro mesmo quando injetado em objetos de vida longa (controllers em cache).
 */
final class AuditLogger
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        string $action,
        ?Model $entity = null,
        array $metadata = [],
        ?User $actor = null,
        ?Tenant $tenant = null,
    ): AuditLog {
        $request = request();
        $actor ??= $request->user() instanceof User ? $request->user() : null;
        $tenantId = $tenant?->getKey() ?? app(TenantContext::class)->current()?->getKey();

        return AuditLog::query()->create([
            'tenant_id' => $tenantId,
            'actor_user_id' => $actor?->getKey(),
            'action' => $action,
            'entity_type' => $entity ? class_basename($entity) : null,
            'entity_id' => $entity ? (string) ($entity->getAttribute('public_id') ?? $entity->getKey()) : null,
            'metadata_json' => $metadata ?: null,
            'ip' => app()->runningInConsole() ? null : $request->ip(),
            'request_id' => AssignRequestId::current($request),
        ]);
    }
}
