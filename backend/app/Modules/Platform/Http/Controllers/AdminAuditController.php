<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\AuditLog;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** GET /admin/audit?tenant=&action=&cursor= — trilha da plataforma inteira. */
class AdminAuditController
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tenant' => ['nullable', 'string', 'size:26'],
            'action' => ['nullable', 'string', 'max:60', 'regex:/^[a-z_.-]+$/'],
            'scope' => ['nullable', 'in:platform,tenants'],
        ]);

        $tenantId = isset($data['tenant']) ? Tenant::query()->where('public_id', $data['tenant'])->value('id') : null;

        $page = AuditLog::query()
            ->when(isset($data['tenant']), fn ($q) => $q->where('tenant_id', $tenantId ?? 0))
            ->when(($data['scope'] ?? null) === 'platform', fn ($q) => $q->whereNull('tenant_id'))
            ->when($data['action'] ?? null, fn ($q, $a) => $q->where('action', 'like', $a.'%'))
            ->orderByDesc('id')
            ->cursorPaginate(50)->withQueryString();

        $items = collect($page->items());
        $actors = User::query()->whereIn('id', $items->pluck('actor_user_id')->filter()->unique())->get(['id', 'name', 'email'])->keyBy('id');
        $tenants = Tenant::query()->whereIn('id', $items->pluck('tenant_id')->filter()->unique())->get(['id', 'name', 'slug'])->keyBy('id');

        return new JsonResponse([
            'data' => $items->map(fn (AuditLog $log) => [
                'id' => (string) $log->id,
                'action' => $log->action,
                'tenant' => $log->tenant_id !== null && isset($tenants[$log->tenant_id]) ? ['name' => $tenants[$log->tenant_id]->name, 'slug' => $tenants[$log->tenant_id]->slug] : null,
                'actor' => $log->actor_user_id !== null && isset($actors[$log->actor_user_id]) ? ['name' => $actors[$log->actor_user_id]->name, 'email' => $actors[$log->actor_user_id]->email] : null,
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
