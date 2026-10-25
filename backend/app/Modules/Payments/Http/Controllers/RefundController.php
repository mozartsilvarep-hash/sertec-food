<?php

namespace App\Modules\Payments\Http\Controllers;

use App\Modules\Identity\Access\TenantAccess;
use App\Modules\Identity\Models\User;
use App\Modules\Ordering\Http\Resources\MerchantOrderResource;
use App\Modules\Ordering\Models\Order;
use App\Modules\Ordering\Services\OrderingException;
use App\Modules\Payments\Services\RefundService;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * POST /merchant/orders/{order}/refunds {amount?, reason?}
 * Sem `amount` = devolve tudo o que ainda está disponível.
 */
class RefundController
{
    public function store(Request $request, Order $order, TenantAccess $access, RefundService $refunds): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        if (! $access->for($user)->canAccessBranch($order->branch_id)) {
            throw new NotFoundHttpException;
        }

        $data = $request->validate([
            'amount' => ['nullable', 'string', 'regex:/^\d{1,8}([.,]\d{1,2})?$/'],
            'reason' => ['nullable', 'string', 'max:200'],
        ]);

        $payment = RefundService::paidPaymentFor($order)
            ?? throw OrderingException::make('nothing_to_refund', 'Este pedido não tem pagamento online a reembolsar.', null, 409);

        $amount = isset($data['amount']) ? Money::toCents(str_replace(',', '.', $data['amount'])) : null;
        $refund = $refunds->refund($payment, $amount, $user, $data['reason'] ?? null);

        $order->refresh()->load(['branch', 'items.options', 'totals', 'history', 'payments.refunds']);

        return new JsonResponse([
            'data' => [
                'refund' => ['id' => $refund->public_id, 'amount' => $refund->amount, 'status' => $refund->status->value],
                'order' => (new MerchantOrderResource($order))->resolve($request),
            ],
        ], 201);
    }
}
