<?php

namespace App\Modules\Cash\Http\Controllers;

use App\Http\Errors\ApiException;
use App\Modules\Cash\Models\Tab;
use App\Modules\Cash\Models\TabItem;
use App\Modules\Cash\Pos\PosSaleService;
use App\Modules\Cash\Tabs\TabService;
use App\Modules\Identity\Access\MemberAccess;
use App\Modules\Identity\Access\TenantAccess;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Permissions;
use App\Modules\Tenancy\Models\Branch;
use App\Modules\Tenancy\Support\PublicIds;
use App\Support\Money;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Mesas e comandas (cash.operate). Fechar = venda do PDV (caixa aberto).
 */
class TabController
{
    public function index(Request $request, TenantAccess $tenantAccess): JsonResponse
    {
        $access = $this->access($request, $tenantAccess);
        $branch = (string) $request->query('branch', '');
        $tabs = Tab::query()->where('status', Tab::OPEN)
            ->when($branch !== '', fn ($q) => $q->where('branch_id', $this->branchFor($access, $branch)->id))
            ->when($access->branchIds !== null, fn ($q) => $q->whereIn('branch_id', $access->branchIds ?? []))
            ->with(['items', 'branch'])->orderBy('opened_at')->limit(300)->get();

        return new JsonResponse(['data' => $tabs->map(fn (Tab $t) => $this->summary($t))->values()]);
    }

    public function store(Request $request, TenantAccess $tenantAccess, TabService $tabs): JsonResponse
    {
        $data = $request->validate([
            'branch' => ['nullable', 'string', 'size:26'],
            'label' => ['required', 'string', 'min:1', 'max:40'],
            'people' => ['nullable', 'integer', 'between:1,99'],
            'customer_name' => ['nullable', 'string', 'max:120'],
        ], [], ['label' => 'mesa ou comanda', 'people' => 'pessoas']);
        $access = $this->access($request, $tenantAccess);
        $branch = $this->resolveBranch($access, $data['branch'] ?? null);
        $tab = $tabs->open($branch, $this->user($request), $data['label'], (int) ($data['people'] ?? 1), $data['customer_name'] ?? null);

        return new JsonResponse(['data' => $this->detail($tab->load(['items', 'branch']))], 201);
    }

    public function show(Request $request, Tab $tab, TenantAccess $tenantAccess): JsonResponse
    {
        $this->guard($request, $tenantAccess, $tab);

        return new JsonResponse(['data' => $this->detail($tab->load(['items.adder', 'branch', 'order']))]);
    }

    public function update(Request $request, Tab $tab, TenantAccess $tenantAccess, TabService $tabs): JsonResponse
    {
        $this->guard($request, $tenantAccess, $tab);
        $data = $request->validate([
            'label' => ['sometimes', 'string', 'min:1', 'max:40'],
            'people' => ['sometimes', 'integer', 'between:1,99'],
            'customer_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:300'],
        ], [], ['label' => 'mesa ou comanda', 'people' => 'pessoas']);
        $locked = $tabs->lockOpen($tab);
        if (isset($data['label'])) {
            $data['label'] = trim(preg_replace('/\s+/', ' ', $data['label']) ?? '');
            $data['open_key'] = Tab::keyFor($data['label']);
        }
        try {
            $locked->fill($data)->save();
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? null) === 1062) {
                throw new ApiException(409, 'tab_already_open', ($data['label'] ?? 'Esta mesa').' já está aberta.');
            }
            throw $e;
        }

        return new JsonResponse(['data' => $this->detail($locked->load(['items.adder', 'branch']))]);
    }

    public function addItems(Request $request, Tab $tab, TenantAccess $tenantAccess, TabService $tabs): JsonResponse
    {
        $this->guard($request, $tenantAccess, $tab);
        $data = $request->validate(PosController::itemRules(), [], PosController::ATTRIBUTES);
        /** @var list<array<string, mixed>> $items */
        $items = $data['items'];
        $tabs->add($tab, $this->user($request), $items);

        return new JsonResponse(['data' => $this->detail($tab->fresh(['items.adder', 'branch']) ?? $tab)], 201);
    }

    public function voidItem(Request $request, Tab $tab, TabItem $item, TenantAccess $tenantAccess, TabService $tabs): JsonResponse
    {
        $this->guard($request, $tenantAccess, $tab);
        if ($item->tab_id !== $tab->id) {
            throw ApiException::notFound();
        }
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:140']], [], ['reason' => 'motivo']);
        $tabs->void($tab, $item, $this->user($request), $data['reason'] ?? null);

        return new JsonResponse(['data' => $this->detail($tab->fresh(['items.adder', 'branch']) ?? $tab)]);
    }

    public function send(Request $request, Tab $tab, TenantAccess $tenantAccess, TabService $tabs): JsonResponse
    {
        $this->guard($request, $tenantAccess, $tab);
        $sent = $tabs->send($tab, $this->user($request));

        return new JsonResponse(['data' => ['batch' => $sent['batch'], 'count' => count($sent['items']), 'tab' => $this->detail($tab->fresh(['items.adder', 'branch']) ?? $tab)]]);
    }

    public function close(Request $request, Tab $tab, TenantAccess $tenantAccess, PosSaleService $pos): JsonResponse
    {
        $access = $this->guard($request, $tenantAccess, $tab);
        $key = PosController::idempotencyKey($request);
        $data = $request->validate(PosController::checkoutRules(), [], PosController::ATTRIBUTES);
        /** @var Branch $branch */
        $branch = Branch::query()->findOrFail($tab->branch_id);
        $result = $pos->sell($branch, $this->user($request), $key, $data, $access->can(Permissions::CASH_MANAGE), $tab);

        return new JsonResponse($result['body'], $result['status']);
    }

    /** Cancelar a conta inteira. Com itens já enviados à cozinha, só gerente. */
    public function cancel(Request $request, Tab $tab, TenantAccess $tenantAccess, TabService $tabs): JsonResponse
    {
        $access = $this->guard($request, $tenantAccess, $tab);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:140']], [], ['reason' => 'motivo']);
        $hasSent = TabItem::query()->where('tab_id', $tab->id)->whereNull('voided_at')->whereNotNull('sent_at')->exists();
        if ($hasSent && ! $access->can(Permissions::CASH_MANAGE)) {
            throw new ApiException(403, 'tab_cancel_not_allowed', 'Itens já foram para a cozinha: peça a um gerente para cancelar.');
        }
        $tabs->cancel($tab, $this->user($request), $data['reason']);

        return new JsonResponse(null, 204);
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Tab $t): array
    {
        $active = $t->items->whereNull('voided_at');

        return [
            'id' => $t->public_id,
            'label' => $t->label,
            'people' => $t->people,
            'customer_name' => $t->customer_name,
            'status' => $t->status,
            'branch' => $t->branch === null ? null : ['id' => $t->branch->public_id, 'name' => $t->branch->name],
            'opened_at' => $t->opened_at->toIso8601String(),
            'items_count' => (int) $active->sum('quantity'),
            'pending_kitchen' => (int) $active->whereNull('sent_at')->count(),
            'subtotal' => Money::format(TabService::subtotalCents($t)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(Tab $t): array
    {
        return $this->summary($t) + [
            'notes' => $t->notes,
            'kitchen_batches' => $t->kitchen_batches,
            'closed_at' => $t->closed_at?->toIso8601String(),
            'order_id' => $t->relationLoaded('order') ? $t->order?->public_id : null,
            'items' => $t->items->map(fn (TabItem $i) => [
                'id' => $i->public_id,
                'name' => $i->name,
                'detail' => $i->detail,
                'unit_price' => $i->unit_price,
                'quantity' => $i->quantity,
                'line_total' => Money::format(Money::toCents((string) $i->unit_price) * $i->quantity),
                'notes' => $i->notes,
                'custom' => $i->product_id === null,
                'kitchen_batch' => $i->kitchen_batch,
                'sent_at' => $i->sent_at?->toIso8601String(),
                'voided_at' => $i->voided_at?->toIso8601String(),
                'void_reason' => $i->void_reason,
                'added_by' => $i->relationLoaded('adder') ? $i->adder?->name : null,
                'added_at' => $i->created_at?->toIso8601String(),
            ])->values(),
        ];
    }

    private function guard(Request $request, TenantAccess $tenantAccess, Tab $tab): MemberAccess
    {
        $access = $this->access($request, $tenantAccess);
        if (! $access->canAccessBranch($tab->branch_id)) {
            throw ApiException::notFound();
        }

        return $access;
    }

    private function resolveBranch(MemberAccess $access, ?string $id): Branch
    {
        if ($id !== null && $id !== '') {
            return $this->branchFor($access, $id);
        }
        $branches = Branch::query()->where('active', true)
            ->when($access->branchIds !== null, fn ($q) => $q->whereIn('id', $access->branchIds ?? []))->limit(2)->get();
        if ($branches->count() !== 1) {
            throw new ApiException(422, 'branch_required', 'Escolha a unidade.');
        }

        return $branches->first();
    }

    private function branchFor(MemberAccess $access, string $id): Branch
    {
        $branch = PublicIds::one(Branch::class, $id, 'branch');
        if (! $access->canAccessBranch($branch->id)) {
            throw ApiException::forbidden();
        }

        return $branch;
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
