<?php

namespace App\Modules\Cash\Http\Controllers;

use App\Http\Errors\ApiException;
use App\Modules\Cash\Models\DiningTable;
use App\Modules\Cash\Models\Tab;
use App\Modules\Cash\Models\TabRequest;
use App\Modules\Cash\Tabs\TableOrderService;
use App\Modules\Cash\Tabs\TabService;
use App\Modules\Identity\Access\MemberAccess;
use App\Modules\Identity\Access\TenantAccess;
use App\Modules\Identity\Models\User;
use App\Modules\Platform\Audit\AuditLogger;
use App\Modules\Tenancy\Models\Branch;
use App\Modules\Tenancy\Models\TenantDomain;
use App\Modules\Tenancy\Support\PublicIds;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Salão para o garçom: mesas cadastradas (QR), pedidos do QR aguardando
 * confirmação e "pedir a conta" (tabs.operate; cadastrar mesas: cash.manage).
 */
class DiningTableController
{
    /** GET cash/floor?branch= — mesas + contas abertas + pedidos aguardando. */
    public function floor(Request $request, TenantAccess $tenantAccess, TableOrderService $orders): JsonResponse
    {
        $branch = $this->branch($request, $tenantAccess);
        $orders->expireOld();
        $tables = DiningTable::query()->where('branch_id', $branch->id)->where('active', true)->orderBy('sort_order')->orderBy('id')->get();
        $tabs = Tab::query()->where('branch_id', $branch->id)->where('status', Tab::OPEN)->with('items')->get()->keyBy('open_key');
        $pending = TabRequest::query()->where('branch_id', $branch->id)->where('status', TabRequest::PENDING)->with('table')->orderBy('id')->get();
        $host = TenantDomain::query()->where('is_primary', true)->value('hostname') ?? TenantDomain::query()->value('hostname');

        $rows = [];
        foreach ($tables as $t) {
            $tab = $tabs->pull(Tab::keyFor($t->label));
            $rows[] = $this->tableRow($t, $tab, $pending, $host);
        }
        // Contas abertas sem mesa cadastrada (comandas, balcão…).
        foreach ($tabs as $tab) {
            $rows[] = ['id' => null, 'label' => $tab->label, 'qr_url' => null, 'tab' => $this->tabSummary($tab), 'pending' => 0];
        }

        return new JsonResponse(['data' => [
            'tables' => $rows,
            'requests' => $pending->map(fn (TabRequest $r) => [
                'id' => $r->public_id, 'type' => $r->type, 'table' => $r->table?->label, 'summary' => $r->summary,
                'customer_name' => $r->customer_name, 'notes' => $r->notes, 'created_at' => $r->created_at->toIso8601String(),
                'lines' => count($r->lines_json ?? []),
            ])->values(),
        ]]);
    }

    public function accept(Request $request, TabRequest $tabRequest, TenantAccess $tenantAccess, TableOrderService $orders): JsonResponse
    {
        $this->guardBranch($request, $tenantAccess, $tabRequest->branch_id);
        $data = $request->validate(['send_to_kitchen' => ['sometimes', 'boolean']]);
        $done = $orders->accept($tabRequest, $this->user($request), (bool) ($data['send_to_kitchen'] ?? false));
        $tab = $done->tab_id !== null ? Tab::query()->find($done->tab_id) : null;

        return new JsonResponse(['data' => ['id' => $done->public_id, 'status' => $done->status, 'tab_id' => $tab?->public_id, 'kitchen_batch' => $tab?->kitchen_batches]]);
    }

    public function reject(Request $request, TabRequest $tabRequest, TenantAccess $tenantAccess, TableOrderService $orders): JsonResponse
    {
        $this->guardBranch($request, $tenantAccess, $tabRequest->branch_id);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:140']]);
        $done = $orders->reject($tabRequest, $this->user($request), $data['reason'] ?? null);

        return new JsonResponse(['data' => ['id' => $done->public_id, 'status' => $done->status]]);
    }

    /** Garçom pede a conta (o caixa vê na lista de mesas). */
    public function bill(Request $request, Tab $tab, TenantAccess $tenantAccess, TabService $tabs): JsonResponse
    {
        $this->guardBranch($request, $tenantAccess, $tab->branch_id);
        $locked = $tabs->lockOpen($tab);
        $locked->forceFill(['bill_requested_at' => $request->boolean('cancel') ? null : CarbonImmutable::now()])->save();

        return new JsonResponse(['data' => ['bill_requested_at' => $locked->bill_requested_at]]);
    }

    /* ---------- Cadastro das mesas (cash.manage) ---------- */

    public function index(Request $request, TenantAccess $tenantAccess): JsonResponse
    {
        $branch = $this->branch($request, $tenantAccess);
        $host = TenantDomain::query()->where('is_primary', true)->value('hostname') ?? TenantDomain::query()->value('hostname');

        return new JsonResponse(['data' => DiningTable::query()->where('branch_id', $branch->id)->orderBy('sort_order')->orderBy('id')->get()
            ->map(fn (DiningTable $t) => $this->present($t, $host))->values()]);
    }

    /** Cria uma mesa ou várias de uma vez ("Mesa 1" a "Mesa 20"). */
    public function store(Request $request, TenantAccess $tenantAccess, AuditLogger $audit): JsonResponse
    {
        $branch = $this->branch($request, $tenantAccess);
        $data = $request->validate([
            'label' => ['required_without:to', 'nullable', 'string', 'min:1', 'max:40'],
            'prefix' => ['required_with:to', 'nullable', 'string', 'max:30'],
            'from' => ['required_with:to', 'nullable', 'integer', 'between:0,999'],
            'to' => ['nullable', 'integer', 'between:0,999', 'gte:from'],
        ], [], ['label' => 'nome', 'prefix' => 'nome', 'from' => 'de', 'to' => 'até']);
        $labels = isset($data['to'])
            ? array_map(fn (int $n) => trim($data['prefix'].' '.$n), range((int) $data['from'], (int) $data['to']))
            : [trim((string) $data['label'])];
        if (count($labels) > 200) {
            throw new ApiException(422, 'validation_failed', 'No máximo 200 mesas de uma vez.');
        }
        $existing = DiningTable::query()->where('branch_id', $branch->id)->pluck('label')->map(fn ($l) => Tab::keyFor($l))->flip();
        $next = (int) DiningTable::query()->where('branch_id', $branch->id)->max('sort_order');
        $created = 0;
        foreach ($labels as $label) {
            if (isset($existing[Tab::keyFor($label)])) {
                continue;
            }
            DiningTable::query()->create(['branch_id' => $branch->id, 'label' => $label, 'qr_token' => DiningTable::newToken(), 'sort_order' => ++$next]);
            $created++;
        }
        $audit->record('dining_tables.created', null, ['count' => $created]);

        return $this->index($request, $tenantAccess)->setStatusCode(201);
    }

    public function update(Request $request, DiningTable $diningTable, TenantAccess $tenantAccess): JsonResponse
    {
        $this->guardBranch($request, $tenantAccess, $diningTable->branch_id);
        $data = $request->validate([
            'label' => ['sometimes', 'string', 'min:1', 'max:40'],
            'active' => ['sometimes', 'boolean'],
            'new_qr' => ['sometimes', 'boolean'],
        ]);
        if (! empty($data['new_qr'])) {
            $diningTable->qr_token = DiningTable::newToken();
        }
        try {
            $diningTable->fill(array_intersect_key($data, array_flip(['label', 'active'])))->save();
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? null) === 1062) {
                throw new ApiException(409, 'table_exists', 'Já existe uma mesa com esse nome.');
            }
            throw $e;
        }
        $host = TenantDomain::query()->where('is_primary', true)->value('hostname') ?? TenantDomain::query()->value('hostname');

        return new JsonResponse(['data' => $this->present($diningTable, $host)]);
    }

    public function destroy(Request $request, DiningTable $diningTable, TenantAccess $tenantAccess): JsonResponse
    {
        $this->guardBranch($request, $tenantAccess, $diningTable->branch_id);
        $diningTable->delete();

        return new JsonResponse(null, 204);
    }

    /**
     * @param  Collection<int, TabRequest>  $pending
     * @return array<string, mixed>
     */
    private function tableRow(DiningTable $t, ?Tab $tab, $pending, ?string $host): array
    {
        return [
            'id' => $t->public_id,
            'label' => $t->label,
            'qr_url' => $host ? "https://{$host}/mesa/{$t->qr_token}" : null,
            'tab' => $tab === null ? null : $this->tabSummary($tab),
            'pending' => $pending->where('dining_table_id', $t->id)->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function tabSummary(Tab $tab): array
    {
        $active = $tab->items->whereNull('voided_at');

        return [
            'id' => $tab->public_id,
            'people' => $tab->people,
            'subtotal' => Money::format((int) $active->sum(fn ($i) => Money::toCents((string) $i->unit_price) * $i->quantity)),
            'items_count' => (int) $active->sum('quantity'),
            'pending_kitchen' => $active->whereNull('sent_at')->count(),
            'bill_requested_at' => $tab->bill_requested_at,
            'opened_at' => $tab->opened_at->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function present(DiningTable $t, ?string $host): array
    {
        return ['id' => $t->public_id, 'label' => $t->label, 'active' => $t->active, 'qr_url' => $host ? "https://{$host}/mesa/{$t->qr_token}" : null];
    }

    private function branch(Request $request, TenantAccess $tenantAccess): Branch
    {
        $access = $this->access($request, $tenantAccess);
        $id = (string) $request->input('branch', $request->query('branch', ''));
        if ($id === '') {
            $branches = Branch::query()->where('active', true)->when($access->branchIds !== null, fn ($q) => $q->whereIn('id', $access->branchIds ?? []))->limit(2)->get();
            if ($branches->count() !== 1) {
                throw new ApiException(422, 'branch_required', 'Escolha a unidade.');
            }

            return $branches->first();
        }
        $branch = PublicIds::one(Branch::class, $id, 'branch');
        if (! $access->canAccessBranch($branch->id)) {
            throw ApiException::forbidden();
        }

        return $branch;
    }

    private function guardBranch(Request $request, TenantAccess $tenantAccess, int $branchId): void
    {
        if (! $this->access($request, $tenantAccess)->canAccessBranch($branchId)) {
            throw ApiException::notFound();
        }
    }

    private function access(Request $request, TenantAccess $tenantAccess): MemberAccess
    {
        return $tenantAccess->for($this->user($request));
    }

    private function user(Request $request): User
    {
        $user = $request->user();

        return $user instanceof User ? $user : throw ApiException::forbidden();
    }
}
