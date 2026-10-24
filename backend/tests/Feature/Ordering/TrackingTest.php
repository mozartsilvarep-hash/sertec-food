<?php

use Tests\Support\CatalogFixture;
use Tests\Support\Shop;

beforeEach(function () {
    $this->a = makeTenant('tenant-a');
    $this->b = makeTenant('tenant-b');
    $this->fa = CatalogFixture::create($this->a);
    CatalogFixture::create($this->b);
    $zone = Shop::enableDelivery($this->fa);
    $token = Shop::newCart($this->a);
    Shop::addPizza($this->fa, $token, 1, ['Calabresa' => 1]);
    $this->tracking = Shop::checkout($this->a, Shop::deliveryPayload($token, $zone, [
        'customer' => ['email' => 'maria@example.com'],
    ]))->json('data.tracking_token');
});

it('mostra andamento, itens e totais pelo token', function () {
    $this->getJson(tenantUrl($this->a, "orders/track/{$this->tracking}"))->assertOk()
        ->assertJsonPath('data.number', 1)
        ->assertJsonPath('data.status_label', 'Aguardando confirmação')
        ->assertJsonPath('data.items.0.name', 'Pizza')
        ->assertJsonPath('data.totals.2.amount', '47.00')
        ->assertJsonPath('data.neighborhood', 'Pajucara')
        ->assertJsonPath('data.customer_first_name', 'Maria')
        ->assertJsonPath('data.customer_phone_masked', '(82) •••••-5432')
        ->assertJsonCount(6, 'data.steps');
});

it('NÃO revela dados sensíveis (telefone completo, rua, número, e-mail, IDs internos)', function () {
    $body = $this->getJson(tenantUrl($this->a, "orders/track/{$this->tracking}"))->getContent();

    expect($body)->not->toContain('99876')
        ->not->toContain('Otacílio')->not->toContain('Otacílio')
        ->not->toContain('1000')
        ->not->toContain('Apto')
        ->not->toContain('maria@example.com')
        ->not->toContain('Souza')
        ->not->toContain('tenant_id')
        ->not->toContain('tracking_token');
});

it('token errado, malformado ou de outro tenant responde 404', function () {
    $this->getJson(tenantUrl($this->a, 'orders/track/'.str_repeat('a', 40)))->assertNotFound();
    $this->getJson(tenantUrl($this->a, 'orders/track/xyz'))->assertNotFound();
    $this->getJson(tenantUrl($this->b, "orders/track/{$this->tracking}"))->assertNotFound();
});
