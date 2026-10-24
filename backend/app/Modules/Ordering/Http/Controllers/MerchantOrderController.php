<?php

namespace App\Modules\Ordering\Http\Controllers;

use App\Modules\Identity\Access\TenantAccess;
use App\Modules\Identity\Models\User;
use App\Modules\Ordering\Enums\CancellationReason;
use App\Modules\Ordering\Enums\OrderStatus;
use App\Modules\Ordering\Http\Resources\MerchantOrderResource;
use App\Modules\Ordering\Models\Order;
use App\Modules\Ordering\Services\OrderTransitions;
use App\Modules\Tenancy\Models\Branch;
use App\Modules\Tenancy\Support\PublicIds;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class MerchantOrderController
{
    private const ACTIVE = ['pending_acceptance', 'accepted', 'preparing', 'ready', 'out_for_delivery'];

    public function index(Request $request, TenantAccess $tenantAccess): AnonymousResourceCollection
    {
        $access = $tenantAccess->for($this->user($request));

        $query = Order::query()
            ->with(['branch', 'items.options'])
            ->when($access->branchIds !== null, fn ($q) => $q->whereIn('branch_id', $access->branchIds ?? []))
            ->orderByDesc('placed_at')->orderByDesc('id');

        $view = (string) $request->query('view', 'active');
        if ($view === 'active') {
            $query->whereIn('status', self::ACTIVE);
        } elseif ($view === 'finished') {
            $query->whereNotIn('status', self::ACTIVE);
        } elseif (OrderStatus::tryFrom($view) !== null) {
            $query->where('status', $view);
        }

        if ($request->filled('branch')) {
            $query->where('branch_id', PublicIds::one(Branch::class, (string) $request->query('branch'), 'branch')->id);
        }

        $perPage = min(100, max(1, (int) $request->query('per_page', '50')));

        return MerchantOrderResource::collection($query->cursorPaginate($perPage)->withQueryString());
    }

    public function show(Request $request, Order $order, TenantAccess $access): MerchantOrderResource
    {
        $this->guard($request, $order, $access);

        return new MerchantOrderResource($order->load(['branch', 'items.options', 'totals', 'history']));
    }

    public function updateStatus(Request $request, Order $order, TenantAccess $access, OrderTransitions $transitions): MerchantOrderResource
    {
        $this->guard($request, $order, $access);

        $data = $request->validate([
            'status' => ['required', Rule::enum(OrderStatus::class)],
            'eta_minutes' => ['nullable', 'integer', 'min:5', 'max:240'],
            'reason' => ['nullable', Rule::enum(CancellationReason::class)],
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

        return new MerchantOrderResource($updated->load(['branch', 'items.options', 'totals', 'history']));
    }

    private function guard(Request $request, Order $order, TenantAccess $access): void
    {
        if (! $access->for($this->user($request))->canAccessBranch($order->branch_id)) {
            throw new NotFoundHttpException;
        }
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
