<?php

namespace App\Modules\Ordering\Http\Controllers;

use App\Modules\Ordering\Http\Resources\TrackedOrderResource;
use App\Modules\Ordering\Models\Order;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * GET /orders/track/{token} — acompanhamento público sem login.
 * O token é comparado pelo hash e escopado ao tenant do host.
 */
class TrackingController
{
    public function __invoke(string $token): TrackedOrderResource
    {
        if (! preg_match('/^[a-f0-9]{40}$/', $token)) {
            throw new NotFoundHttpException;
        }

        $order = Order::query()
            ->with(['branch', 'items.options', 'totals', 'history'])
            ->where('tracking_token_hash', hash('sha256', $token))
            ->first();

        return new TrackedOrderResource($order ?? throw new NotFoundHttpException);
    }
}
