<?php

use App\Modules\Ordering\Models\Order;
use App\Modules\Payments\Models\Payment;
use Tests\Support\CatalogFixture;
use Tests\Support\FakeMercadoPago;
use Tests\Support\Pay;
use Tests\Support\Shop;

beforeEach(function () {
    $this->mp = FakeMercadoPago::install();
    $this->a = makeTenant('tenant-a');
    $this->fa = CatalogFixture::create($this->a);
});

it('sem Mercado Pago conectado: Pix e cartão online são recusados e não aparecem no storefront', function () {
    $this->getJson(tenantUrl($this->a, 'storefront'))->assertOk()
        ->assertJsonPath('data.payment_methods.*.method', ['cash', 'card_on_delivery']);

    $token = Shop::newCart($this->a);
    Shop::addPizza($this->fa, $token);
    Shop::checkout($this->a, Shop::pickupPayload($token, ['customer' => ['email' => 'a@b.com'], 'payment' => ['method' => 'pix']]))
        ->assertStatus(422)->assertJsonPath('code', 'payment_method_unavailable');

    expect(inTenant($this->a, fn () => Order::query()->count()))->toBe(0);
});

it('Pix exige e-mail do pagador', function () {
    $this->mp->connect($this->a);
    $token = Shop::newCart($this->a);
    Shop::addPizza($this->fa, $token);

    Shop::checkout($this->a, Shop::pickupPayload($token, ['payment' => ['method' => 'pix']]))
        ->assertStatus(422)->assertJsonPath('code', 'validation_failed')->assertJsonStructure(['details' => ['fields' => ['customer.email']]]);
});

it('Pix: pedido fica aguardando pagamento, cobrança idempotente com valor do servidor e QR no acompanhamento', function () {
    $this->mp->connect($this->a);
    $this->getJson(tenantUrl($this->a, 'storefront'))
        ->assertJsonPath('data.payment_methods.*.method', ['cash', 'card_on_delivery', 'pix', 'online_card']);

    $placed = Pay::order($this->fa, 'pix');
    $placed['response']->assertJsonPath('data.status', 'awaiting_payment')
        ->assertJsonPath('data.payment_required', true)
        ->assertJsonPath('data.total', '40.00');

    $payment = Pay::payment($this->a, $placed['order_id']);
    $call = $this->mp->requestsTo('/v1/payments')[0];
    expect($call['idempotency'])->toBe($payment->public_id)
        ->and($call['body']['transaction_amount'])->toEqual(40.0)
        ->and($call['body']['external_reference'])->toBe($payment->public_id)
        ->and($call['body']['payment_method_id'])->toBe('pix')
        ->and($call['body']['payer']['email'])->toBe('maria@example.com')
        // Host sem HTTPS: sem notification_url (a reconciliação confirma).
        ->and($call['body'])->not->toHaveKey('notification_url')
        ->and($payment->external_id)->toBe($this->mp->lastPaymentId())
        ->and($payment->expires_at->diffInMinutes(now(), true))->toBeGreaterThan(29);

    $this->getJson(tenantUrl($this->a, "orders/track/{$placed['tracking']}"))->assertOk()
        ->assertJsonPath('data.status', 'awaiting_payment')
        ->assertJsonPath('data.steps.0.status', 'awaiting_payment')
        ->assertJsonPath('data.payment.payable', true)
        ->assertJsonPath('data.payment.needs_start', false)
        ->assertJsonPath('data.payment.pix.qr_code', fn ($v) => str_starts_with($v, '000201'))
        ->assertJsonMissingPath('data.payment.external_id');
});

it('pedido aguardando pagamento não aparece para o restaurante', function () {
    $this->mp->connect($this->a);
    $placed = Pay::order($this->fa, 'pix');
    $session = merchantLogin($this->a, makeMember($this->a));

    withSession($session)->getJson(tenantUrl($this->a, 'merchant/orders?view=active'))->assertOk()->assertJsonCount(0, 'data');
    withSession($session)->getJson(tenantUrl($this->a, 'merchant/orders?view=finished'))->assertOk()->assertJsonCount(0, 'data');
    withSession($session)->getJson(tenantUrl($this->a, "merchant/orders/{$placed['order_id']}"))->assertNotFound();
    withSession($session)->patchJson(tenantUrl($this->a, "merchant/orders/{$placed['order_id']}/status"), ['status' => 'accepted'])->assertNotFound();
});

it('cartão online: preferência do Checkout Pro com retorno e webhook no host HTTPS, sem token de acompanhamento', function () {
    $this->mp->connect($this->a);
    $placed = Pay::order($this->fa, 'online_card', https: true);

    $call = $this->mp->requestsTo('/checkout/preferences')[0];
    $host = hostOf($this->a);
    expect($call['body']['items'][0]['unit_price'])->toEqual(40.0)
        ->and($call['body']['notification_url'])->toBe("https://{$host}/api/v1/webhooks/mercadopago?source_news=webhooks")
        ->and($call['body']['back_urls']['success'])->toBe("https://{$host}/pedido/retorno")
        ->and(json_encode($call['body']))->not->toContain($placed['tracking'])
        ->and(collect($call['body']['payment_methods']['excluded_payment_types'])->pluck('id')->all())->toContain('ticket');

    // Credencial de teste usa o sandbox_init_point.
    $this->getJson(tenantUrl($this->a, "orders/track/{$placed['tracking']}"))
        ->assertJsonPath('data.payment.checkout_url', fn ($u) => str_starts_with($u, 'https://sandbox.mercadopago.com.br/'))
        ->assertJsonPath('data.payment.pix', null);
});

it('provedor fora do ar: o pedido é criado e o cliente gera a cobrança de novo, sem duplicar', function () {
    $this->mp->connect($this->a);
    $this->mp->failNext['POST /v1/payments'] = 500;

    $placed = Pay::order($this->fa, 'pix');
    $this->getJson(tenantUrl($this->a, "orders/track/{$placed['tracking']}"))
        ->assertJsonPath('data.payment.needs_start', true)->assertJsonPath('data.payment.pix', null);
    expect(Pay::payment($this->a, $placed['order_id'])->last_error)->toBe('create_pix_failed');

    $this->postJson(tenantUrl($this->a, "orders/track/{$placed['tracking']}/payment"))->assertOk()
        ->assertJsonPath('data.payment.needs_start', false)
        ->assertJsonPath('data.payment.pix.qr_code', fn ($v) => is_string($v));

    // Mesma chave de idempotência nas duas tentativas.
    $keys = array_column($this->mp->requestsTo('/v1/payments'), 'idempotency');
    expect($keys)->toHaveCount(2)->and(array_unique($keys))->toHaveCount(1)
        ->and(inTenant($this->a, fn () => Payment::query()->count()))->toBe(1);
});

it('"já paguei" consulta o provedor e não confia no navegador', function () {
    $this->mp->connect($this->a);
    $placed = Pay::order($this->fa, 'pix');

    $this->postJson(tenantUrl($this->a, "orders/track/{$placed['tracking']}/payment"), ['status' => 'approved'])
        ->assertOk()->assertJsonPath('data.status', 'awaiting_payment');

    $this->mp->set($this->mp->lastPaymentId(), 'approved');
    $this->postJson(tenantUrl($this->a, "orders/track/{$placed['tracking']}/payment"))
        ->assertOk()->assertJsonPath('data.status', 'pending_acceptance')->assertJsonPath('data.payment_status', 'paid');

    $this->postJson(tenantUrl($this->a, "orders/track/{$placed['tracking']}/payment"))
        ->assertStatus(409)->assertJsonPath('code', 'payment_not_pending');
});

it('repetir o checkout com a mesma chave não gera nova cobrança', function () {
    $this->mp->connect($this->a);
    $token = Shop::newCart($this->a);
    Shop::addPizza($this->fa, $token);
    $payload = Shop::pickupPayload($token, ['customer' => ['email' => 'a@b.com'], 'payment' => ['method' => 'pix']]);

    $first = Shop::checkout($this->a, $payload, 'mesma-chave-123456789')->assertCreated();
    Shop::checkout($this->a, $payload, 'mesma-chave-123456789')->assertCreated()
        ->assertJsonPath('data.order_id', $first->json('data.order_id'));

    expect($this->mp->requestsTo('/v1/payments'))->toHaveCount(1)
        ->and(inTenant($this->a, fn () => Payment::query()->count()))->toBe(1);
});
