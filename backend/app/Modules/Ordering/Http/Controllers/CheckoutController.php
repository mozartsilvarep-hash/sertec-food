<?php

namespace App\Modules\Ordering\Http\Controllers;

use App\Modules\Ordering\Http\Requests\CheckoutRequest;
use App\Modules\Ordering\Services\CheckoutService;
use App\Modules\Ordering\Services\OrderingException;
use App\Modules\Payments\Gateway\GatewayException;
use App\Modules\Payments\Services\PaymentInitiator;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class CheckoutController
{
    public function __invoke(CheckoutRequest $request, CheckoutService $checkout, PaymentInitiator $payments): JsonResponse
    {
        $key = (string) $request->header('Idempotency-Key', '');
        if (! preg_match('/^[A-Za-z0-9_-]{16,100}$/', $key)) {
            throw OrderingException::make('idempotency_key_required', 'Cabeçalho Idempotency-Key ausente ou inválido.', null, 400);
        }

        $result = $checkout->checkout($key, $request->validated());

        // Pedido já gravado; gerar a cobrança é "melhor esforço": se o provedor
        // falhar, o acompanhamento oferece tentar de novo (mesma chave idempotente).
        if (($result['payment'] ?? null) !== null) {
            try {
                $payments->start($result['payment'], $request);
            } catch (GatewayException $e) {
                Log::warning('payments.start_failed', ['payment' => $result['payment']->public_id, 'reason' => $e->reason]);
            }
        }

        return new JsonResponse($result['body'], $result['status']);
    }
}
