<?php

namespace App\Modules\Ordering\Http\Controllers;

use App\Modules\Ordering\Enums\OrderStatus;
use App\Modules\Ordering\Http\Resources\TrackedOrderResource;
use App\Modules\Ordering\Models\Order;
use App\Modules\Ordering\Services\OrderingException;
use App\Modules\Payments\Gateway\GatewayException;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Services\PaymentInitiator;
use App\Modules\Payments\Services\PaymentSync;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * GET /orders/track/{token} — acompanhamento público sem login.
 * O token é comparado pelo hash e escopado ao tenant do host.
 *
 * POST /orders/track/{token}/payment — (re)gera a cobrança online quando a
 * primeira tentativa falhou por rede, ou consulta o provedor ("Já paguei").
 * Nunca marca como pago sozinho: só o status lido na API do provedor vale.
 */
class TrackingController
{
    public function __invoke(string $token): TrackedOrderResource
    {
        return new TrackedOrderResource($this->find($token));
    }

    public function payment(Request $request, string $token, PaymentInitiator $initiator, PaymentSync $sync): TrackedOrderResource
    {
        $order = $this->find($token);
        /** @var Payment|null $payment */
        $payment = $order->payments->last();

        if ($order->status !== OrderStatus::AwaitingPayment || $payment === null) {
            throw OrderingException::make('payment_not_pending', 'Este pedido não está aguardando pagamento.', null, 409);
        }

        if ($payment->isStarted()) {
            try {
                $sync->refresh($payment);
            } catch (GatewayException $e) {
                Log::info('payments.refresh_failed', ['payment' => $payment->public_id, 'reason' => $e->reason]);
            }
        } else {
            $initiator->start($payment, $request);
        }

        return new TrackedOrderResource($this->find($token));
    }

    private function find(string $token): Order
    {
        if (! preg_match('/^[a-f0-9]{40}$/', $token)) {
            throw new NotFoundHttpException;
        }

        return Order::query()
            ->with(['branch', 'items.options', 'totals', 'history', 'payments'])
            ->where('tracking_token_hash', hash('sha256', $token))
            ->first() ?? throw new NotFoundHttpException;
    }
}
