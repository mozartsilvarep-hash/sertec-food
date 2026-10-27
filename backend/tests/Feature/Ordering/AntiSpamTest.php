<?php

use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\Support\CatalogFixture;
use Tests\Support\Shop;

beforeEach(function () {
    RateLimiter::clear('checkout');
    $this->a = makeTenant('tenant-a');
    $this->fa = CatalogFixture::create($this->a);
});

function spamOrder(object $t, array $extra = [], string $ip = '203.0.113.10')
{
    $token = Shop::newCart($t->a);
    Shop::addSoda($t->fa, $token);

    return test()->withServerVariables(['REMOTE_ADDR' => $ip])
        ->postJson(tenantUrl($t->a, 'checkout'), Shop::pickupPayload($token, $extra), ['Idempotency-Key' => (string) Str::uuid()]);
}

it('campo invisível preenchido (robô) é recusado sem criar pedido', function () {
    spamOrder($this, ['website' => 'http://spam.example'])->assertStatus(422)->assertJsonStructure(['details' => ['fields' => ['website']]]);
    spamOrder($this)->assertCreated();
});

it('o mesmo celular não cria pedidos em massa, mesmo trocando de IP', function () {
    foreach (range(1, 20) as $i) {
        spamOrder($this, ['customer' => ['name' => 'Teste', 'phone' => '(79) 99999-0001']], "203.0.113.{$i}")->assertCreated();
    }
    spamOrder($this, ['customer' => ['name' => 'Teste', 'phone' => '(79) 99999-0001']], '198.51.100.7')->assertStatus(429);
    spamOrder($this, ['customer' => ['name' => 'Outra', 'phone' => '(79) 99999-0002']], '198.51.100.8')->assertCreated();
});

it('painel tem limite geral por usuário', function () {
    $session = merchantLogin($this->a, makeMember($this->a));
    withSession($session)->getJson(tenantUrl($this->a, 'merchant/me'))->assertOk()->assertHeader('X-RateLimit-Limit', '600');
});
