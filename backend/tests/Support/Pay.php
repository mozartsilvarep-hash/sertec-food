<?php

namespace Tests\Support;

use App\Modules\Ordering\Models\Order;
use App\Modules\Payments\Models\Payment;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Testing\TestResponse;

/**
 * Atalhos para os testes de pagamento online.
 */
final class Pay
{
    /**
     * Pedido de retirada (pizza média calabresa) pago online.
     *
     * @return array{order_id: string, tracking: string, response: TestResponse}
     */
    public static function order(CatalogFixture $f, string $method = 'pix', bool $https = false): array
    {
        $token = Shop::newCart($f->tenant);
        Shop::addPizza($f, $token, 1, ['Calabresa' => 1]);
        $payload = Shop::pickupPayload($token, [
            'customer' => ['email' => 'maria@example.com'],
            'payment' => ['method' => $method],
        ]);

        $url = tenantUrl($f->tenant, 'checkout');
        if ($https) {
            $url = str_replace('http://', 'https://', $url);
        }
        $response = test()->withHeader('Idempotency-Key', 'k-'.bin2hex(random_bytes(10)))->postJson($url, $payload)->assertCreated();

        return [
            'order_id' => (string) $response->json('data.order_id'),
            'tracking' => (string) $response->json('data.tracking_token'),
            'response' => $response,
        ];
    }

    public static function payment(Tenant $tenant, string $orderPublicId): Payment
    {
        return inTenant($tenant, fn () => Payment::query()
            ->whereHas('order', fn ($q) => $q->where('public_id', $orderPublicId))
            ->latest('id')->firstOrFail());
    }

    public static function orderModel(Tenant $tenant, string $orderPublicId): Order
    {
        return inTenant($tenant, fn () => Order::query()->with('history')->where('public_id', $orderPublicId)->firstOrFail());
    }

    /**
     * Notificação do Mercado Pago assinada como o provedor faria.
     *
     * @param  array<string, mixed>  $body
     */
    public static function webhook(Tenant $tenant, string $dataId, ?string $secret = 'segredo-webhook-de-teste-123456', array $body = [], ?string $notificationId = null): TestResponse
    {
        $requestId = 'req-'.bin2hex(random_bytes(6));
        $ts = (string) time();
        $headers = ['x-request-id' => $requestId];
        if ($secret !== null) {
            $hash = hash_hmac('sha256', "id:{$dataId};request-id:{$requestId};ts:{$ts};", $secret);
            $headers['x-signature'] = "ts={$ts},v1={$hash}";
        }

        return test()->withHeaders($headers)->postJson(
            tenantUrl($tenant, 'webhooks/mercadopago?data.id='.$dataId.'&type=payment'),
            array_replace([
                'id' => $notificationId ?? (string) random_int(10_000_000, 99_999_999),
                'live_mode' => false,
                'type' => 'payment',
                'action' => 'payment.updated',
                'data' => ['id' => $dataId],
            ], $body),
        );
    }
}
