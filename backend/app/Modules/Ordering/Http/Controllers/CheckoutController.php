<?php

namespace App\Modules\Ordering\Http\Controllers;

use App\Modules\Ordering\Http\Requests\CheckoutRequest;
use App\Modules\Ordering\Services\CheckoutService;
use App\Modules\Ordering\Services\OrderingException;
use Illuminate\Http\JsonResponse;

class CheckoutController
{
    public function __invoke(CheckoutRequest $request, CheckoutService $checkout): JsonResponse
    {
        $key = (string) $request->header('Idempotency-Key', '');
        if (! preg_match('/^[A-Za-z0-9_-]{16,100}$/', $key)) {
            throw OrderingException::make('idempotency_key_required', 'Cabeçalho Idempotency-Key ausente ou inválido.', null, 400);
        }

        $result = $checkout->checkout($key, $request->validated());

        return new JsonResponse($result['body'], $result['status']);
    }
}
