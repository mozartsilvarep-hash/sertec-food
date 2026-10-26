<?php

use App\Modules\Ordering\Models\Order;
use App\Modules\Ordering\Privacy\CustomerPrivacy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\CatalogFixture;
use Tests\Support\Shop;

beforeEach(function () {
    $this->a = makeTenant('tenant-a');
    $this->b = makeTenant('tenant-b');
    $this->fa = CatalogFixture::create($this->a);
    $this->zone = Shop::enableDelivery($this->fa);
    RateLimiter::clear('phone-lookup');
});

function deliveredOrder($test, array $address = []): void
{
    $token = Shop::newCart($test->a);
    Shop::addPizza($test->fa, $token, 1, ['Calabresa' => 1]);
    Shop::checkout($test->a, Shop::deliveryPayload($token, $test->zone, ['fulfillment' => ['address' => $address + ['reference' => 'Em frente à padaria']]]))->assertCreated();
}

function lookup($tenant, string $phone)
{
    return test()->postJson(tenantUrl($tenant, 'customers/lookup'), ['phone' => $phone]);
}

it('celular desconhecido: só "não conhecido"; telefone inválido em português', function () {
    lookup($this->a, '(82) 99876-5432')->assertOk()->assertExactJson(['data' => ['known' => false, 'first_name' => null, 'saved_address' => null]]);
    lookup($this->a, '123')->assertStatus(422)->assertJsonPath('details.fields.phone.0', 'Informe um celular com DDD, ex.: (79) 99999-0000.');
});

it('cliente conhecido: primeiro nome, rua e bairro — nunca número, complemento ou referência', function () {
    deliveredOrder($this);

    $res = lookup($this->a, '82998765432')->assertOk();
    expect($res->json('data'))->toBe([
        'known' => true,
        'first_name' => 'Maria',
        'saved_address' => ['street' => 'Av. Álvaro Otacílio', 'number' => '•••', 'neighborhood' => 'Pajucara'],
    ]);
    $raw = $res->getContent();
    expect($raw)->not->toContain('1000')->not->toContain('Apto')->not->toContain('padaria')->not->toContain('Souza');

    // Mesmo celular em outro restaurante: desconhecido (isolamento por tenant).
    lookup($this->b, '82998765432')->assertOk()->assertJsonPath('data.known', false);
});

it('checkout com "usar endereço salvo": o servidor completa o endereço pelo celular', function () {
    deliveredOrder($this);

    $token = Shop::newCart($this->a);
    Shop::addPizza($this->fa, $token, 1, ['Calabresa' => 1]);
    $payload = Shop::deliveryPayload($token, $this->zone);
    unset($payload['fulfillment']['address']);
    $payload['fulfillment']['use_saved_address'] = true;
    Shop::checkout($this->a, $payload)->assertCreated();

    $last = inTenant($this->a, fn () => Order::query()->orderByDesc('id')->first());
    expect($last->address_snapshot_json)->toMatchArray([
        'street' => 'Av. Álvaro Otacílio', 'number' => '1000', 'complement' => 'Apto 302', 'neighborhood' => 'Pajucara', 'reference' => 'Em frente à padaria',
    ]);

    // Celular sem endereço salvo: pede para preencher.
    $other = Shop::newCart($this->a);
    Shop::addPizza($this->fa, $other, 1, ['Calabresa' => 1]);
    $payload['cart_token'] = $other;
    $payload['customer']['phone'] = '(82) 99111-2222';
    Shop::checkout($this->a, $payload)->assertStatus(422)->assertJsonPath('code', 'saved_address_missing');
});

it('dados apagados a pedido do titular (LGPD) não aparecem mais', function () {
    deliveredOrder($this);
    inTenant($this->a, fn () => Order::query()->update(['status' => 'completed']));
    inTenant($this->a, fn () => app(CustomerPrivacy::class)->anonymizeCustomer('82998765432'));

    lookup($this->a, '82998765432')->assertOk()->assertJsonPath('data.known', false);
    $this->postJson(tenantUrl($this->a, 'orders/lookup'), ['phone' => '82998765432'])->assertOk()->assertExactJson(['data' => []]);
});

it('acompanhar pelo celular: só situação das últimas 24 h, sem nome nem endereço', function () {
    deliveredOrder($this);
    $token = Shop::newCart($this->a);
    Shop::addSoda($this->fa, $token, 1);
    Shop::checkout($this->a, Shop::pickupPayload($token))->assertCreated();
    // Pedido antigo fica de fora.
    inTenant($this->a, fn () => Order::query()->where('order_number', 1)->update(['placed_at' => CarbonImmutable::now()->subHours(30)]));

    $res = $this->postJson(tenantUrl($this->a, 'orders/lookup'), ['phone' => '82 99876-5432'])->assertOk();
    expect($res->json('data'))->toHaveCount(1)
        ->and(array_keys($res->json('data.0')))->toBe(['number', 'placed_at', 'status', 'status_label', 'fulfillment_type', 'total', 'branch'])
        ->and($res->json('data.0'))->toMatchArray(['number' => 2, 'status' => 'pending_acceptance', 'status_label' => 'Aguardando confirmação', 'total' => '6.50']);
    expect($res->getContent())->not->toContain('Maria')->not->toContain('Álvaro');

    $this->postJson(tenantUrl($this->b, 'orders/lookup'), ['phone' => '82998765432'])->assertOk()->assertExactJson(['data' => []]);
});

it('limite de consultas por IP barra varredura de celulares', function () {
    foreach (range(1, 10) as $i) {
        lookup($this->a, sprintf('(82) 9%04d-%04d', $i, $i))->assertOk();
    }
    lookup($this->a, '(82) 99999-0011')->assertStatus(429);
});
