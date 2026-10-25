<?php

use App\Modules\Identity\Models\Role;
use App\Modules\Payments\Models\IntegrationConnection;
use App\Modules\Payments\Models\Refund;
use Tests\Support\CatalogFixture;
use Tests\Support\FakeMercadoPago;
use Tests\Support\Pay;
use Tests\Support\Shop;

beforeEach(function () {
    $this->mp = FakeMercadoPago::install();
    $this->a = makeTenant('tenant-a');
    $this->b = makeTenant('tenant-b');
    $this->fa = CatalogFixture::create($this->a);
    $this->fb = CatalogFixture::create($this->b);
    $this->mp->connect($this->a);
    $this->mp->connect($this->b);
    $this->owner = makeMember($this->a);
});

function tokenOf(object $t): string
{
    return inTenant($t->a, fn () => IntegrationConnection::query()->sole()->accessToken());
}

/** Pedido de A pago (Pix aprovado + webhook). */
function paidOrder(object $t): string
{
    $placed = Pay::order($t->fa, 'pix');
    $t->mp->set($t->mp->lastPaymentId(), 'approved');
    Pay::webhook($t->a, $t->mp->lastPaymentId())->assertOk();

    return $placed['order_id'];
}

it('reconciliação: confirma Pix pago sem webhook (ambiente sem HTTPS)', function () {
    $placed = Pay::order($this->fa, 'pix');
    $this->mp->set($this->mp->lastPaymentId(), 'approved');

    $this->travel(1)->minutes();
    $this->artisan('payments:reconcile')->assertSuccessful();

    expect(Pay::orderModel($this->a, $placed['order_id'])->status->value)->toBe('pending_acceptance');
});

it('reconciliação: cartão do Checkout Pro é achado pela referência (recusado e depois aprovado)', function () {
    $placed = Pay::order($this->fa, 'online_card');
    $payment = Pay::payment($this->a, $placed['order_id']);

    $this->mp->cardPayment(tokenOf($this), $payment->public_id, 'rejected', 40.0);
    $this->travel(1)->minutes();
    $this->artisan('payments:reconcile')->assertSuccessful();
    expect(Pay::payment($this->a, $placed['order_id'])->status->value)->toBe('rejected');
    $this->getJson(tenantUrl($this->a, "orders/track/{$placed['tracking']}"))
        ->assertJsonPath('data.payment.rejected', true)->assertJsonPath('data.payment.payable', true);

    $approved = $this->mp->cardPayment(tokenOf($this), $payment->public_id, 'approved', 40.0);
    $this->travel(1)->minutes();
    $this->artisan('payments:reconcile')->assertSuccessful();

    $payment = Pay::payment($this->a, $placed['order_id']);
    expect($payment->status->value)->toBe('approved')->and($payment->external_id)->toBe($approved)
        ->and(Pay::orderModel($this->a, $placed['order_id'])->status->value)->toBe('pending_acceptance');
});

it('prazo vencido: pagamento expira, pedido é cancelado e o QR some do acompanhamento', function () {
    $placed = Pay::order($this->fa, 'pix');

    $this->travel(31)->minutes();
    $this->artisan('payments:reconcile')->assertSuccessful();
    // Dentro da tolerância ainda não expira.
    expect(Pay::orderModel($this->a, $placed['order_id'])->status->value)->toBe('awaiting_payment');
    $this->getJson(tenantUrl($this->a, "orders/track/{$placed['tracking']}"))->assertJsonPath('data.payment.pix', null);

    $this->travel(2)->minutes();
    $this->artisan('payments:reconcile')->assertSuccessful();

    $order = Pay::orderModel($this->a, $placed['order_id']);
    expect($order->status->value)->toBe('cancelled')
        ->and($order->cancellation_reason)->toBe('payment_not_completed')
        ->and($order->payment_status->value)->toBe('expired')
        ->and(Pay::payment($this->a, $placed['order_id'])->status->value)->toBe('expired');
});

it('pagou depois do cancelamento por prazo: o dinheiro volta automaticamente', function () {
    $placed = Pay::order($this->fa, 'pix');
    $mpId = $this->mp->lastPaymentId();
    $this->travel(40)->minutes();
    $this->artisan('payments:reconcile')->assertSuccessful();

    $this->mp->set($mpId, 'approved');
    Pay::webhook($this->a, $mpId)->assertOk();

    $order = Pay::orderModel($this->a, $placed['order_id']);
    expect($order->status->value)->toBe('cancelled')
        ->and($order->payment_status->value)->toBe('refunded')
        ->and($this->mp->requestsTo("/v1/payments/{$mpId}/refunds"))->toHaveCount(1)
        ->and($this->mp->payments[$mpId]['status'])->toBe('refunded');
});

it('reembolso parcial e depois o restante; acima do disponível é recusado', function () {
    $order = paidOrder($this);
    $session = merchantLogin($this->a, $this->owner);
    $url = tenantUrl($this->a, "merchant/orders/{$order}/refunds");

    withSession($session)->postJson($url, ['amount' => '15,50', 'reason' => 'Faltou a borda'])->assertCreated()
        ->assertJsonPath('data.refund.amount', '15.50')
        ->assertJsonPath('data.order.payment_status', 'partially_refunded')
        ->assertJsonPath('data.order.online_payment.refundable', '24.50');

    withSession($session)->postJson($url, ['amount' => '30.00'])->assertStatus(422)->assertJsonPath('code', 'refund_amount_invalid');

    withSession($session)->postJson($url)->assertCreated()
        ->assertJsonPath('data.refund.amount', '24.50')
        ->assertJsonPath('data.order.payment_status', 'refunded')
        ->assertJsonPath('data.order.online_payment.refunds.*.amount', ['15.50', '24.50']);

    withSession($session)->postJson($url)->assertStatus(409)->assertJsonPath('code', 'nothing_to_refund');

    $calls = $this->mp->requestsTo('/v1/payments/'.$this->mp->lastPaymentId().'/refunds');
    $refunds = inTenant($this->a, fn () => Refund::query()->orderBy('id')->get());
    expect($calls)->toHaveCount(2)
        ->and($calls[0]['body']['amount'])->toEqual(15.5)
        ->and($calls[1]['body']['amount'])->toEqual(24.5)
        ->and(array_column($calls, 'idempotency'))->toBe($refunds->pluck('public_id')->all());
});

it('falha do provedor no reembolso: registra a falha e não muda o pagamento', function () {
    $order = paidOrder($this);
    $session = merchantLogin($this->a, $this->owner);
    $this->mp->failNext['POST /v1/payments/{id}/refunds'] = 500;

    withSession($session)->postJson(tenantUrl($this->a, "merchant/orders/{$order}/refunds"))
        ->assertStatus(502)->assertJsonPath('code', 'payment_provider_unavailable');

    expect(inTenant($this->a, fn () => Refund::query()->sole()->status->value))->toBe('failed')
        ->and(Pay::payment($this->a, $order)->status->value)->toBe('approved')
        ->and(Pay::orderModel($this->a, $order)->payment_status->value)->toBe('paid');
});

it('restaurante cancela pedido pago online: reembolso automático', function () {
    $order = paidOrder($this);
    $session = merchantLogin($this->a, $this->owner);

    withSession($session)->patchJson(tenantUrl($this->a, "merchant/orders/{$order}/status"), ['status' => 'rejected', 'reason' => 'out_of_stock'])
        ->assertOk();

    expect(Pay::orderModel($this->a, $order)->payment_status->value)->toBe('refunded')
        ->and(inTenant($this->a, fn () => Refund::query()->sole()->requested_by_user_id))->toBe($this->owner->id);
});

it('motivo automático não pode ser usado pelo restaurante', function () {
    $order = paidOrder($this);
    $session = merchantLogin($this->a, $this->owner);

    withSession($session)->patchJson(tenantUrl($this->a, "merchant/orders/{$order}/status"), ['status' => 'rejected', 'reason' => 'payment_not_completed'])
        ->assertStatus(422);
});

it('permissões e isolamento do reembolso', function () {
    $order = paidOrder($this);

    $attendant = merchantLogin($this->a, makeMember($this->a, role: Role::ATTENDANT));
    withSession($attendant)->postJson(tenantUrl($this->a, "merchant/orders/{$order}/refunds"))->assertForbidden();

    // Dono de B, no host de B, com o ID do pedido de A: não existe.
    $ownerB = merchantLogin($this->b, makeMember($this->b));
    withSession($ownerB)->postJson(tenantUrl($this->b, "merchant/orders/{$order}/refunds"))->assertNotFound();

    expect($this->mp->requestsTo('/v1/payments/'.$this->mp->lastPaymentId().'/refunds'))->toBe([]);
});

it('pagamento na entrega não tem reembolso online', function () {
    $session = merchantLogin($this->a, $this->owner);
    $token = Shop::newCart($this->a);
    Shop::addPizza($this->fa, $token);
    $order = Shop::checkout($this->a, Shop::pickupPayload($token))->json('data.order_id');

    withSession($session)->postJson(tenantUrl($this->a, "merchant/orders/{$order}/refunds"))
        ->assertStatus(409)->assertJsonPath('code', 'nothing_to_refund');
    withSession($session)->getJson(tenantUrl($this->a, "merchant/orders/{$order}"))->assertJsonPath('data.online_payment', null);
});
