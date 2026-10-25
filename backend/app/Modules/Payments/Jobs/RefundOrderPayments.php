<?php

namespace App\Modules\Payments\Jobs;

use App\Modules\Identity\Models\User;
use App\Modules\Ordering\Models\Order;
use App\Modules\Payments\Services\RefundService;
use App\Modules\Tenancy\Jobs\TenantJob;

/**
 * Devolve o pagamento online de um pedido cancelado (pelo restaurante ou pago
 * depois do prazo). Repetível: se já foi devolvido, não há o que fazer.
 */
class RefundOrderPayments extends TenantJob
{
    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [60, 300, 900, 3600];

    public function __construct(public int $orderId, public ?int $actorId)
    {
        parent::__construct();
    }

    public function handle(RefundService $refunds): void
    {
        $order = Order::query()->find($this->orderId);
        $payment = $order === null ? null : RefundService::paidPaymentFor($order);

        if ($payment === null || RefundService::refundableCents($payment) === 0) {
            return;
        }

        $actor = $this->actorId === null ? null : User::query()->find($this->actorId);
        $refunds->refund($payment, null, $actor, 'Pedido cancelado');
    }
}
