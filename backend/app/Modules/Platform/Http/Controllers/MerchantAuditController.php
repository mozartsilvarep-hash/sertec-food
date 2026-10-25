<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\AuditLog;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /merchant/audit — trilha do próprio estabelecimento (audit.view).
 * audit_logs não tem TenantScope (exceção documentada), então o filtro por
 * tenant_id é EXPLÍCITO aqui e coberto por teste de isolamento.
 */
class MerchantAuditController
{
    public function index(Request $request, TenantContext $context): JsonResponse
    {
        $data = $request->validate([
            'action' => ['nullable', 'string', 'max:60', 'regex:/^[a-z_.-]+$/'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = AuditLog::query()
            ->where('tenant_id', $context->id())
            ->when($data['action'] ?? null, fn ($q, $a) => $q->where('action', 'like', $a.'%'))
            ->orderByDesc('id')
            ->cursorPaginate((int) ($data['per_page'] ?? 50))
            ->withQueryString();

        $actors = User::query()->whereIn('id', collect($page->items())->pluck('actor_user_id')->filter()->unique())
            ->get(['id', 'name', 'public_id'])->keyBy('id');

        return new JsonResponse([
            'data' => collect($page->items())->map(fn (AuditLog $log) => [
                'id' => (string) $log->id,
                'action' => $log->action,
                'actor' => $log->actor_user_id !== null && isset($actors[$log->actor_user_id])
                    ? ['id' => $actors[$log->actor_user_id]->public_id, 'name' => $actors[$log->actor_user_id]->name]
                    : null,
                'entity_type' => $log->entity_type,
                'entity_id' => $log->entity_id,
                'metadata' => $log->metadata_json,
                'ip' => $log->getAttribute('ip'),
                'at' => $log->created_at->toIso8601String(),
            ])->values(),
            'meta' => ['next_cursor' => $page->nextCursor()?->encode()],
        ]);
    }
}
