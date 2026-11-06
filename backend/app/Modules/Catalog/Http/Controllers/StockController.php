<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Http\Errors\ApiException;
use App\Modules\Catalog\Models\BranchProductAvailability;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductStock;
use App\Modules\Catalog\Models\StockMovement;
use App\Modules\Catalog\Stock\StockMovementType;
use App\Modules\Catalog\Stock\StockService;
use App\Modules\Identity\Access\TenantAccess;
use App\Modules\Identity\Models\User;
use App\Modules\Platform\Audit\AuditLogger;
use App\Modules\Tenancy\Models\Branch;
use App\Modules\Tenancy\Support\PublicIds;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Estoque simples por unidade (ver StockService). Consultar: catalog.view;
 * ligar o controle e ajustar: catalog.manage.
 */
class StockController
{
    public function index(Request $request, TenantAccess $tenantAccess): JsonResponse
    {
        $branch = $this->branch($request, $tenantAccess);
        $stocks = ProductStock::query()->where('branch_id', $branch->id)->get()->keyBy('product_id');
        $paused = BranchProductAvailability::query()->where('branch_id', $branch->id)->where('available', false)->pluck('product_id')->flip();
        $products = Product::query()->where('kind', '!=', 'combo')->orderBy('name')->get(['id', 'public_id', 'name', 'code', 'active']);

        return new JsonResponse(['data' => [
            'branch' => ['id' => $branch->public_id, 'name' => $branch->name],
            'products' => $products->map(function (Product $p) use ($stocks, $paused) {
                $s = $stocks[$p->id] ?? null;

                return [
                    'id' => $p->public_id, 'name' => $p->name, 'code' => $p->code, 'active' => $p->active,
                    'tracked' => $s !== null, 'quantity' => $s?->quantity, 'min_quantity' => $s?->min_quantity,
                    'low' => $s?->isLow() ?? false, 'sold_out' => isset($paused[$p->id]),
                ];
            })->values(),
        ]]);
    }

    /** Liga/desliga o controle e define o mínimo (e o saldo inicial ao ligar). */
    public function update(Request $request, Product $product, TenantAccess $tenantAccess, StockService $stock, AuditLogger $audit): JsonResponse
    {
        $branch = $this->branch($request, $tenantAccess);
        $data = $request->validate([
            'tracked' => ['required', 'boolean'],
            'quantity' => ['nullable', 'integer', 'between:0,999999'],
            'min_quantity' => ['nullable', 'integer', 'between:0,999999'],
        ], [], ['quantity' => 'quantidade', 'min_quantity' => 'mínimo']);
        if ($product->kind === 'combo') {
            throw new ApiException(422, 'stock_combo', 'Combo não tem estoque próprio: controle os produtos que vão nele.');
        }

        DB::transaction(function () use ($data, $branch, $product, $stock, $audit, $request) {
            $row = ProductStock::query()->where('branch_id', $branch->id)->where('product_id', $product->id)->lockForUpdate()->first();
            if (! $data['tracked']) {
                if ($row !== null) {
                    if ($row->auto_paused) {
                        BranchProductAvailability::query()->where('branch_id', $branch->id)->where('product_id', $product->id)->update(['available' => true]);
                    }
                    $row->delete();
                    $audit->record('stock.disabled', $product, ['branch' => $branch->public_id]);
                }

                return;
            }
            if ($row === null) {
                $row = ProductStock::query()->create(['branch_id' => $branch->id, 'product_id' => $product->id, 'quantity' => 0, 'min_quantity' => (int) ($data['min_quantity'] ?? 0)]);
                $stock->adjust($row, StockMovementType::Count, (int) ($data['quantity'] ?? 0), $this->user($request), 'Início do controle');
                $audit->record('stock.enabled', $product, ['branch' => $branch->public_id, 'quantity' => (int) ($data['quantity'] ?? 0)]);

                return;
            }
            if (array_key_exists('min_quantity', $data) && $data['min_quantity'] !== null) {
                $row->forceFill(['min_quantity' => (int) $data['min_quantity']])->save();
            }
        });

        return $this->index($request, $tenantAccess);
    }

    public function adjust(Request $request, Product $product, TenantAccess $tenantAccess, StockService $stock, AuditLogger $audit): JsonResponse
    {
        $branch = $this->branch($request, $tenantAccess);
        $data = $request->validate([
            'type' => ['required', Rule::in([StockMovementType::Entry->value, StockMovementType::Loss->value, StockMovementType::Count->value])],
            'quantity' => ['required', 'integer', 'between:0,999999'],
            'reason' => ['nullable', 'string', 'max:140'],
        ], [], ['type' => 'tipo', 'quantity' => 'quantidade', 'reason' => 'motivo']);
        $type = StockMovementType::from($data['type']);
        if ($type !== StockMovementType::Count && (int) $data['quantity'] === 0) {
            throw new ApiException(422, 'validation_failed', 'Informe a quantidade.', ['fields' => ['quantity' => ['Informe a quantidade.']]]);
        }
        $row = ProductStock::query()->where('branch_id', $branch->id)->where('product_id', $product->id)->first()
            ?? throw new ApiException(422, 'stock_not_tracked', 'Ligue o controle de estoque deste produto primeiro.');

        $after = DB::transaction(fn () => $stock->adjust($row, $type, (int) $data['quantity'], $this->user($request), $data['reason'] ?? null));
        $audit->record('stock.adjusted', $product, ['branch' => $branch->public_id, 'type' => $type->value, 'quantity' => (int) $data['quantity'], 'balance' => $after->quantity]);

        return new JsonResponse(['data' => ['quantity' => $after->quantity, 'low' => $after->isLow(), 'sold_out' => $after->auto_paused]]);
    }

    public function movements(Request $request, Product $product, TenantAccess $tenantAccess): JsonResponse
    {
        $branch = $this->branch($request, $tenantAccess);
        $rows = StockMovement::query()->where('branch_id', $branch->id)->where('product_id', $product->id)
            ->with(['user', 'order'])->orderByDesc('id')->limit(100)->get();

        return new JsonResponse(['data' => $rows->map(fn (StockMovement $m) => [
            'type' => $m->type->value,
            'label' => $m->type->label(),
            'quantity' => $m->quantity,
            'balance_after' => $m->balance_after,
            'reason' => $m->reason,
            'user' => $m->user?->name,
            'order_number' => $m->order?->order_number,
            'occurred_at' => $m->occurred_at->toIso8601String(),
        ])->values()]);
    }

    private function branch(Request $request, TenantAccess $tenantAccess): Branch
    {
        $access = $tenantAccess->for($this->user($request));
        $id = (string) $request->input('branch', $request->query('branch', ''));
        if ($id === '') {
            $branches = Branch::query()->when($access->branchIds !== null, fn ($q) => $q->whereIn('id', $access->branchIds ?? []))->orderBy('id')->limit(2)->get();
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

    private function user(Request $request): User
    {
        $user = $request->user();

        return $user instanceof User ? $user : throw ApiException::forbidden();
    }
}
