<?php

namespace App\Modules\Ordering\Http\Controllers;

use App\Modules\Identity\Access\TenantAccess;
use App\Modules\Identity\Models\User;
use App\Modules\Ordering\Enums\CancellationReason;
use App\Modules\Ordering\Enums\OrderStatus;
use App\Modules\Ordering\Http\Resources\MerchantOrderResource;
use App\Modules\Ordering\Models\Order;
use App\Modules\Ordering\Services\OrderFilters;
use App\Modules\Ordering\Services\OrderPulse;
use App\Modules\Ordering\Services\OrderTransitions;
use App\Modules\Tenancy\Models\Branch;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class MerchantOrderController
{
    public function index(Request $request, TenantAccess $tenantAccess, TenantContext $context): AnonymousResourceCollection
    {
        $access = $tenantAccess->for($this->user($request));

        $query = OrderFilters::view(
            OrderFilters::query($request, $access, $context->get()->timezone),
            (string) $request->query('view', 'active'),
        )->with(['branch', 'items.options', 'payments.refunds'])->orderByDesc('placed_at')->orderByDesc('id');

        $perPage = min(100, max(1, (int) $request->query('per_page', '50')));

        return MerchantOrderResource::collection($query->cursorPaginate($perPage)->withQueryString());
    }

    /**
     * GET /merchant/orders/pulse — consultado a cada 3 s pelo painel.
     * Lê o contador no cache; só a contagem de "novos" vai ao banco (índice).
     */
    public function pulse(Request $request, TenantAccess $tenantAccess, TenantContext $context): JsonResponse
    {
        $access = $tenantAccess->for($this->user($request));
        $tenantId = $context->id();
        $branchIds = $access->branchIds ?? Branch::query()->pluck('id')->map(fn ($id) => (int) $id)->all();

        $pending = Order::query()
            ->where('status', OrderStatus::PendingAcceptance)
            ->whereIn('branch_id', $branchIds)
            ->selectRaw('COUNT(*) AS c, MIN(placed_at) AS oldest')
            ->toBase()->first();

        return new JsonResponse(['data' => [
            'version' => OrderPulse::version($tenantId, $branchIds),
            'pending' => (int) ($pending->c ?? 0),
            'oldest_pending_at' => isset($pending->oldest) ? Carbon::parse((string) $pending->oldest, 'UTC')->toIso8601String() : null,
        ]]);
    }

    public function show(Request $request, Order $order, TenantAccess $access): MerchantOrderResource
    {
        $this->guard($request, $order, $access);

        return new MerchantOrderResource($order->load(['branch', 'items.options', 'totals', 'history', 'payments.refunds']));
    }

    public function updateStatus(Request $request, Order $order, TenantAccess $access, OrderTransitions $transitions): MerchantOrderResource
    {
        $this->guard($request, $order, $access);

        $data = $request->validate([
            'status' => ['required', Rule::enum(OrderStatus::class)],
            'eta_minutes' => ['nullable', 'integer', 'min:5', 'max:240'],
            'reason' => ['nullable', Rule::enum(CancellationReason::class)->except(CancellationReason::PaymentNotCompleted)],
            'note' => ['nullable', 'string', 'max:300'],
        ]);

        $updated = $transitions->transition(
            $order,
            OrderStatus::from($data['status']),
            $this->user($request),
            isset($data['eta_minutes']) ? (int) $data['eta_minutes'] : null,
            isset($data['reason']) ? CancellationReason::from($data['reason']) : null,
            $data['note'] ?? null,
        );

        return new MerchantOrderResource($updated->load(['branch', 'items.options', 'totals', 'history', 'payments.refunds']));
    }

    private function guard(Request $request, Order $order, TenantAccess $access): void
    {
        if ($order->status === OrderStatus::AwaitingPayment || ! $access->for($this->user($request))->canAccessBranch($order->branch_id)) {
            throw new NotFoundHttpException;
        }
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
