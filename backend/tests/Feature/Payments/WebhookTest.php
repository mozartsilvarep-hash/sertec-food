<?php

use App\Modules\Payments\Models\IntegrationConnection;
use App\Modules\Payments\Models\PaymentEvent;
use App\Modules\Payments\Services\WebhookSignature;
use App\Modules\Platform\Models\AuditLog;
use Tests\Support\CatalogFixture;
use Tests\Support\FakeMercadoPago;
use Tests\Support\Pay;

beforeEach(function () {
    $this->mp = FakeMercadoPago::install();
    $this->a = makeTenant('tenant-a');
    $this->b = makeTenant('tenant-b');
    $this->fa = CatalogFixture::create($this->a);
    $this->fb = CatalogFixture::create($this->b);
    $this->mp->connect($this->a);
    $this->mp->connect($this->b);
});

it('assinatura no formato do SDK oficial: manifest, partes ausentes e comparação segura', function () {
    $secret = 'abc123segredo';
    $hash = hash_hmac('sha256', 'id:123456;request-id:req-1;ts:1704908010;', $secret);

    expect(WebhookSignature::manifest('123456', 'req-1', '1704908010'))->toBe('id:123456;request-id:req-1;ts:1704908010;')
        ->and(WebhookSignature::manifest(null, null, '1'))->toBe('ts:1;')
        ->and(WebhookSignature::valid("ts=1704908010,v1={$hash}", 'req-1', '123456', $secret))->toBeTrue()
        ->and(WebhookSignature::valid(" ts=1704908010 , v1={$hash} ", 'req-1', '123456', $secret))->toBeTrue()
        ->and(WebhookSignature::valid("ts=1704908010,v1={$hash}", 'req-2', '123456', $secret))->toBeFalse()
        ->and(WebhookSignature::valid("ts=1704908011,v1={$hash}", 'req-1', '123456', $secret))->toBeFalse()
        ->and(WebhookSignature::valid("ts=1704908010,v1={$hash}", 'req-1', '123456', 'outro'))->toBeFalse()
        ->and(WebhookSignature::valid("v1={$hash}", 'req-1', '123456', $secret))->toBeFalse()
        ->and(WebhookSignature::valid(null, 'req-1', '123456', $secret))->toBeFalse()
        ->and(WebhookSignature::valid('lixo', 'req-1', '123456', $secret))->toBeFalse();

    // data.id alfanumérico: aceita minúsculas (documentação) e como recebido (SDK).
    $lower = hash_hmac('sha256', 'id:abc;request-id:r;ts:1;', $secret);
    expect(WebhookSignature::valid("ts=1,v1={$lower}", 'r', 'ABC', $secret))->toBeTrue();
});

it('webhook assinado confirma o pagamento consultando a API: pedido entra no painel', function () {
    $placed = Pay::order($this->fa, 'pix');
    $mpId = $this->mp->lastPaymentId();
    $this->mp->set($mpId, 'approved', ['status_detail' => 'accredited']);

    Pay::webhook($this->a, $mpId)->assertOk()->assertJsonPath('status', 'received');

    $order = Pay::orderModel($this->a, $placed['order_id']);
    $payment = Pay::payment($this->a, $placed['order_id']);
    expect($order->status->value)->toBe('pending_acceptance')
        ->and($order->payment_status->value)->toBe('paid')
        ->and($payment->status->value)->toBe('approved')
        ->and($payment->approved_at)->not->toBeNull()
        ->and($order->history->pluck('new_status.value')->all())->toBe(['awaiting_payment', 'pending_acceptance'])
        ->and($order->history->last()->actor_user_id)->toBeNull();

    $event = inTenant($this->a, fn () => PaymentEvent::query()->sole());
    expect($event->status)->toBe('processed')->and($event->signature_valid)->toBeTrue();

    // Agora o restaurante vê o pedido.
    $session = merchantLogin($this->a, makeMember($this->a));
    withSession($session)->getJson(tenantUrl($this->a, 'merchant/orders'))
        ->assertJsonPath('data.0.id', $placed['order_id'])
        ->assertJsonPath('data.0.online_payment.status', 'approved');
});

it('assinatura inválida ou ausente com segredo configurado: 401 e nada é gravado', function () {
    $placed = Pay::order($this->fa, 'pix');
    $mpId = $this->mp->lastPaymentId();
    $this->mp->set($mpId, 'approved');

    Pay::webhook($this->a, $mpId, secret: 'segredo-errado-0000000000')->assertStatus(401)->assertJsonPath('code', 'invalid_signature');
    Pay::webhook($this->a, $mpId, secret: null)->assertStatus(401);

    expect(inTenant($this->a, fn () => PaymentEvent::query()->count()))->toBe(0)
        ->and(Pay::payment($this->a, $placed['order_id'])->status->value)->toBe('pending');
});

it('notificação repetida é deduplicada e o pagamento é aplicado uma vez', function () {
    $placed = Pay::order($this->fa, 'pix');
    $mpId = $this->mp->lastPaymentId();
    $this->mp->set($mpId, 'approved');

    Pay::webhook($this->a, $mpId, notificationId: '777')->assertOk()->assertJsonPath('status', 'received');
    Pay::webhook($this->a, $mpId, notificationId: '777')->assertOk()->assertJsonPath('status', 'duplicate');
    // Outra notificação do mesmo pagamento (ex.: payment.created + updated) não repete efeitos.
    Pay::webhook($this->a, $mpId, notificationId: '778')->assertOk();

    $order = Pay::orderModel($this->a, $placed['order_id']);
    expect(inTenant($this->a, fn () => PaymentEvent::query()->count()))->toBe(2)
        ->and($order->history)->toHaveCount(2)
        ->and(inTenant($this->a, fn () => AuditLog::query()->where('action', 'payment.approved')->count()))->toBe(1);
});

it('o corpo do webhook não é fonte de verdade: "approved" no corpo com API pendente não libera', function () {
    $placed = Pay::order($this->fa, 'pix');
    $mpId = $this->mp->lastPaymentId();

    Pay::webhook($this->a, $mpId, body: ['status' => 'approved', 'data' => ['id' => $mpId, 'status' => 'approved']])->assertOk();

    expect(Pay::orderModel($this->a, $placed['order_id'])->status->value)->toBe('awaiting_payment');
});

it('valor pago diferente do total: pagamento marcado como divergente e pedido não é liberado', function () {
    $placed = Pay::order($this->fa, 'pix');
    $mpId = $this->mp->lastPaymentId();
    $this->mp->set($mpId, 'approved', ['transaction_amount' => 0.01]);

    Pay::webhook($this->a, $mpId)->assertOk();

    $payment = Pay::payment($this->a, $placed['order_id']);
    expect($payment->status->value)->toBe('mismatch')
        ->and($payment->last_error)->toBe('amount')
        ->and(Pay::orderModel($this->a, $placed['order_id'])->status->value)->toBe('awaiting_payment')
        ->and(inTenant($this->a, fn () => AuditLog::query()->where('action', 'payment.mismatch')->count()))->toBe(1);
});

it('isolamento: webhook no host do tenant B com o pagamento do A não toca no pedido do A', function () {
    $placed = Pay::order($this->fa, 'pix');
    $mpId = $this->mp->lastPaymentId();
    $this->mp->set($mpId, 'approved');

    // B consulta com o próprio token: a API responde 404 (pagamento de outra conta).
    Pay::webhook($this->b, $mpId)->assertOk();

    expect(Pay::orderModel($this->a, $placed['order_id'])->status->value)->toBe('awaiting_payment')
        ->and(inTenant($this->b, fn () => PaymentEvent::query()->sole()->status))->toBe('ignored')
        ->and(inTenant($this->a, fn () => PaymentEvent::query()->count()))->toBe(0)
        ->and(collect($this->mp->requestsTo('/v1/payments/'.$mpId))->pluck('token')->unique()->all())
        ->not->toContain(inTenant($this->a, fn () => IntegrationConnection::query()->sole()->accessToken()));
});

it('isolamento: pagamento de outra conta com external_reference de um pedido nosso não é aceito', function () {
    $placed = Pay::order($this->fa, 'pix');
    $paymentA = Pay::payment($this->a, $placed['order_id']);
    $tokenB = inTenant($this->b, fn () => IntegrationConnection::query()->sole()->accessToken());

    // Alguém cria, na conta B, um pagamento aprovado apontando para o pedido de A.
    $forged = $this->mp->cardPayment($tokenB, $paymentA->public_id, 'approved', 40.0);
    Pay::webhook($this->b, $forged)->assertOk();

    // No tenant B a referência não existe (TenantScope): nada acontece com A.
    expect(Pay::orderModel($this->a, $placed['order_id'])->status->value)->toBe('awaiting_payment')
        ->and(inTenant($this->b, fn () => PaymentEvent::query()->sole()->status))->toBe('ignored');
});

it('sem segredo configurado: aceita notificação não assinada, mas só confia na consulta à API', function () {
    inTenant($this->a, function () {
        $c = IntegrationConnection::query()->sole();
        $c->update(['credentials' => ['access_token' => $c->accessToken(), 'webhook_secret' => null]]);
    });
    $placed = Pay::order($this->fa, 'pix');
    $mpId = $this->mp->lastPaymentId();

    Pay::webhook($this->a, $mpId, secret: null)->assertOk();
    expect(Pay::orderModel($this->a, $placed['order_id'])->status->value)->toBe('awaiting_payment')
        ->and(inTenant($this->a, fn () => PaymentEvent::query()->sole()->signature_valid))->toBeFalse();

    $this->mp->set($mpId, 'approved');
    Pay::webhook($this->a, $mpId, secret: null)->assertOk();
    expect(Pay::orderModel($this->a, $placed['order_id'])->status->value)->toBe('pending_acceptance');
});

it('tópicos que não são pagamento e notificações sem ID válido são ignorados sem gravar', function () {
    Pay::order($this->fa, 'pix');

    $this->postJson(tenantUrl($this->a, 'webhooks/mercadopago?data.id=1&type=merchant_order'), ['type' => 'merchant_order', 'data' => ['id' => '1']])
        ->assertStatus(401); // sem assinatura com segredo configurado

    Pay::webhook($this->a, '1', body: ['type' => 'merchant_order'])->assertOk()->assertJsonPath('status', 'ignored');
    Pay::webhook($this->a, 'abc')->assertOk()->assertJsonPath('status', 'ignored');

    expect(inTenant($this->a, fn () => PaymentEvent::query()->count()))->toBe(0);
});

it('falha temporária ao consultar a API: evento fica com falha e a reconciliação reprocessa', function () {
    $placed = Pay::order($this->fa, 'pix');
    $mpId = $this->mp->lastPaymentId();
    $this->mp->set($mpId, 'approved');
    $this->mp->failNext['GET /v1/payments/{id}'] = 503;

    Pay::webhook($this->a, $mpId)->assertOk();
    $event = inTenant($this->a, fn () => PaymentEvent::query()->sole());
    expect($event->status)->toBe('failed')
        ->and(Pay::orderModel($this->a, $placed['order_id'])->status->value)->toBe('awaiting_payment');

    $this->travel(6)->minutes();
    $this->artisan('payments:reconcile')->assertSuccessful();

    expect(Pay::orderModel($this->a, $placed['order_id'])->status->value)->toBe('pending_acceptance');
});

it('pagamento consultado com referência de outro pedido ou recebedor diferente não libera', function () {
    $placed = Pay::order($this->fa, 'pix');
    $mpId = $this->mp->lastPaymentId();

    $this->mp->set($mpId, 'approved', ['external_reference' => '01JOUTROPEDIDO0000000000000']);
    $this->travel(1)->minutes();
    $this->artisan('payments:reconcile')->assertSuccessful();
    expect(Pay::orderModel($this->a, $placed['order_id'])->status->value)->toBe('awaiting_payment');

    $this->mp->set($mpId, 'approved', ['external_reference' => Pay::payment($this->a, $placed['order_id'])->public_id, 'collector_id' => 1]);
    $this->travel(1)->minutes();
    $this->artisan('payments:reconcile')->assertSuccessful();
    expect(Pay::orderModel($this->a, $placed['order_id'])->status->value)->toBe('awaiting_payment')
        ->and(Pay::payment($this->a, $placed['order_id'])->last_error)->toBe('collector');
});

it('pagamento confirmado não volta a pendente por uma consulta desatualizada', function () {
    $placed = Pay::order($this->fa, 'pix');
    $mpId = $this->mp->lastPaymentId();
    $this->mp->set($mpId, 'approved');
    Pay::webhook($this->a, $mpId)->assertOk();

    $this->mp->set($mpId, 'pending');
    Pay::webhook($this->a, $mpId)->assertOk();

    expect(Pay::payment($this->a, $placed['order_id'])->status->value)->toBe('approved')
        ->and(Pay::orderModel($this->a, $placed['order_id'])->payment_status->value)->toBe('paid');
});

it('nenhum filtro do painel mostra pedido aguardando pagamento', function () {
    Pay::order($this->fa, 'pix');
    $session = merchantLogin($this->a, makeMember($this->a));

    foreach (['all', 'awaiting_payment', 'active', 'finished'] as $view) {
        withSession($session)->getJson(tenantUrl($this->a, "merchant/orders?view={$view}"))->assertOk()->assertJsonCount(0, 'data');
    }
});
