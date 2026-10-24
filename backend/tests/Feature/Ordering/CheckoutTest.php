<?php

use App\Modules\Catalog\Models\BranchProductAvailability;
use App\Modules\Ordering\Http\Requests\CheckoutRequest;
use App\Modules\Ordering\Models\Customer;
use App\Modules\Ordering\Models\IdempotencyKey;
use App\Modules\Ordering\Models\Order;
use App\Modules\Tenancy\Models\Branch;
use App\Modules\Tenancy\Models\BranchOpeningHour;
use Carbon\CarbonImmutable;
use Tests\Support\CatalogFixture;
use Tests\Support\Shop;

beforeEach(function () {
    $this->a = makeTenant('tenant-a');
    $this->b = makeTenant('tenant-b');
    $this->fa = CatalogFixture::create($this->a);
    $this->fb = CatalogFixture::create($this->b);
    $this->zone = Shop::enableDelivery($this->fa);
});

it('retirada: cria pedido com snapshots, totais, histórico e token de acompanhamento', function () {
    $token = Shop::newCart($this->a);
    Shop::addPizza($this->fa, $token, 1, ['Calabresa' => 1, 'Portuguesa' => 1], ['Bacon' => 2]);

    $response = Shop::checkout($this->a, Shop::pickupPayload($token, ['notes' => 'Sem cebola']))->assertCreated();

    expect($response->json('data'))->toMatchArray([
        'order_number' => 1, 'status' => 'pending_acceptance', 'payment_status' => 'pending',
        'fulfillment_type' => 'pickup', 'total' => '49.51', 'eta_minutes' => 30,
    ])->and($response->json('data.tracking_token'))->toMatch('/^[a-f0-9]{40}$/');

    $order = inTenant($this->a, fn () => Order::query()->with(['items.options', 'totals', 'history'])->sole());
    expect($order->tracking_token_hash)->toBe(hash('sha256', $response->json('data.tracking_token')))
        ->and($order->customer_phone)->toBe('82998765432')
        ->and($order->items[0]->product_name_snapshot)->toBe('Pizza')
        ->and($order->items[0]->options->pluck('option_name_snapshot')->sort()->values()->all())->toBe(['Bacon', 'Calabresa', 'Portuguesa'])
        ->and($order->totals->pluck('code')->all())->toBe(['subtotal', 'total'])
        ->and($order->history->sole()->new_status->value)->toBe('pending_acceptance');

    // Carrinho convertido não pode ser reutilizado.
    $this->getJson(tenantUrl($this->a, "carts/{$token}"))->assertNotFound();
});

it('ignora qualquer preço ou total enviado pelo navegador', function () {
    $token = Shop::newCart($this->a);
    Shop::addSoda($this->fa, $token, 2);

    $payload = Shop::pickupPayload($token, ['total' => '0.01', 'subtotal' => '0.01', 'items' => [['price' => '0.01']], 'delivery_fee' => '-50']);

    Shop::checkout($this->a, $payload)->assertCreated()->assertJsonPath('data.total', '13.00');
});

it('entrega: taxa da zona, bairro sem acento, frete grátis acima do valor e endereço no snapshot', function () {
    $token = Shop::newCart($this->a);
    Shop::addPizza($this->fa, $token, 1, ['Calabresa' => 1]);

    Shop::checkout($this->a, Shop::deliveryPayload($token, $this->zone))->assertCreated()
        ->assertJsonPath('data.total', '47.00')->assertJsonPath('data.eta_minutes', 50);

    $order = inTenant($this->a, fn () => Order::query()->with('totals')->sole());
    expect($order->delivery_zone_name)->toBe('Orla')
        ->and($order->address_snapshot_json['street'])->toBe('Av. Álvaro Otacílio')
        ->and($order->totals->pluck('amount', 'code')->all())->toBe(['subtotal' => '40.00', 'delivery_fee' => '7.00', 'total' => '47.00']);

    $big = Shop::newCart($this->a);
    Shop::addPizza($this->fa, $big, 3, ['Calabresa' => 1]);
    Shop::checkout($this->a, Shop::deliveryPayload($big, $this->zone))->assertCreated()->assertJsonPath('data.total', '120.00');
});

it('entrega: pedido mínimo, bairro fora da zona, zona de outro tenant e entrega desligada', function () {
    $token = Shop::newCart($this->a);
    Shop::addSoda($this->fa, $token);

    Shop::checkout($this->a, Shop::deliveryPayload($token, $this->zone))->assertStatus(422)->assertJsonPath('code', 'minimum_order_not_met');

    Shop::addPizza($this->fa, $token);
    Shop::checkout($this->a, Shop::deliveryPayload($token, $this->zone, ['fulfillment' => ['address' => ['neighborhood' => 'Farol']]]))
        ->assertStatus(422)->assertJsonPath('code', 'neighborhood_not_served');

    $zoneB = Shop::enableDelivery($this->fb);
    Shop::checkout($this->a, Shop::deliveryPayload($token, $zoneB))->assertStatus(422)->assertJsonPath('code', 'zone_invalid');

    inTenant($this->a, fn () => $this->fa->branch->update(['delivery_enabled' => false]));
    Shop::checkout($this->a, Shop::deliveryPayload($token, $this->zone))->assertStatus(422)->assertJsonPath('code', 'delivery_unavailable');

    expect(inTenant($this->a, fn () => Order::query()->count()))->toBe(0);
});

it('IDEMPOTÊNCIA: mesma chave e mesmos dados devolvem o mesmo pedido', function () {
    $token = Shop::newCart($this->a);
    Shop::addSoda($this->fa, $token);
    $payload = Shop::pickupPayload($token);

    $first = Shop::checkout($this->a, $payload, 'chave-unica-123456789')->assertCreated();
    $second = Shop::checkout($this->a, $payload, 'chave-unica-123456789')->assertCreated();

    expect($second->json())->toBe($first->json())
        ->and(inTenant($this->a, fn () => Order::query()->count()))->toBe(1);
});

it('IDEMPOTÊNCIA: mesma chave com outros dados é conflito; sem chave é recusado', function () {
    $token = Shop::newCart($this->a);
    Shop::addSoda($this->fa, $token);

    Shop::checkout($this->a, Shop::pickupPayload($token), 'chave-unica-abcdefghij')->assertCreated();
    Shop::checkout($this->a, Shop::pickupPayload($token, ['customer' => ['name' => 'Outra Pessoa']]), 'chave-unica-abcdefghij')
        ->assertStatus(409)->assertJsonPath('code', 'idempotency_conflict');

    $this->flushHeaders()->postJson(tenantUrl($this->a, 'checkout'), Shop::pickupPayload($token))
        ->assertStatus(400)->assertJsonPath('code', 'idempotency_key_required');
});

it('IDEMPOTÊNCIA: falha de negócio não queima a chave (cliente pode corrigir e reenviar)', function () {
    $token = Shop::newCart($this->a);

    Shop::checkout($this->a, Shop::pickupPayload($token), 'chave-retry-1234567890')->assertStatus(422)->assertJsonPath('code', 'cart_empty');
    Shop::addSoda($this->fa, $token);
    Shop::checkout($this->a, Shop::pickupPayload($token), 'chave-retry-1234567890')->assertCreated();
});

it('ISOLAMENTO: a mesma chave em tenants diferentes não colide', function () {
    $ta = Shop::newCart($this->a);
    Shop::addSoda($this->fa, $ta);
    $tb = Shop::newCart($this->b);
    Shop::addSoda($this->fb, $tb);

    Shop::checkout($this->a, Shop::pickupPayload($ta), 'mesma-chave-1234567890')->assertCreated();
    Shop::checkout($this->b, Shop::pickupPayload($tb), 'mesma-chave-1234567890')->assertCreated();
});

it('ISOLAMENTO: carrinho de A não fecha pedido no host de B', function () {
    $token = Shop::newCart($this->a);
    Shop::addSoda($this->fa, $token);

    Shop::checkout($this->b, Shop::pickupPayload($token))->assertNotFound()->assertJsonPath('code', 'cart_not_found');
    expect(inTenant($this->b, fn () => Order::query()->count()))->toBe(0);
});

it('numeração sequencial por unidade', function () {
    $other = inTenant($this->a, fn () => Branch::query()->create(['name' => 'Jatiúca']));
    $numbers = [];

    foreach ([null, null, $other->public_id] as $branch) {
        $token = (string) $this->postJson(tenantUrl($this->a, 'carts'), ['branch_id' => $branch])->json('data.token');
        Shop::addSoda($this->fa, $token);
        $numbers[] = Shop::checkout($this->a, Shop::pickupPayload($token))->json('data.order_number');
    }

    expect($numbers)->toBe([1, 2, 1]);
});

it('loja fechada não recebe pedido', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-23 10:00', 'America/Sao_Paulo')); // sexta, 10h
    inTenant($this->a, fn () => BranchOpeningHour::query()->create([
        'branch_id' => $this->fa->branch->id, 'weekday' => 5, 'opens_at' => '18:00', 'closes_at' => '23:00',
    ]));
    $token = Shop::newCart($this->a);
    Shop::addSoda($this->fa, $token);

    Shop::checkout($this->a, Shop::pickupPayload($token))->assertStatus(422)
        ->assertJsonPath('code', 'store_closed')
        ->assertJsonPath('details.next_opening_at', '2026-10-23T18:00:00-03:00');

    $this->travelTo(CarbonImmutable::parse('2026-10-23 19:00', 'America/Sao_Paulo'));
    Shop::checkout($this->a, Shop::pickupPayload($token))->assertCreated();
});

it('carrinho com item esgotado é recusado com a lista de itens', function () {
    $token = Shop::newCart($this->a);
    $item = Shop::addSoda($this->fa, $token)->json('data.items.0.id');
    inTenant($this->a, fn () => BranchProductAvailability::query()->create([
        'branch_id' => $this->fa->branch->id, 'product_id' => $this->fa->soda->id, 'available' => false,
    ]));

    Shop::checkout($this->a, Shop::pickupPayload($token))->assertStatus(422)
        ->assertJsonPath('code', 'cart_invalid')
        ->assertJsonPath('details.items.0.id', $item)
        ->assertJsonPath('details.items.0.problem.reason', 'product_sold_out');
});

it('valida celular, troco e dados de entrega', function () {
    $token = Shop::newCart($this->a);
    Shop::addSoda($this->fa, $token);

    Shop::checkout($this->a, Shop::pickupPayload($token, ['customer' => ['phone' => '1234']]))
        ->assertStatus(422)->assertJsonStructure(['details' => ['fields' => ['customer.phone']]]);
    Shop::checkout($this->a, Shop::pickupPayload($token, ['payment' => ['change_for' => '5.00']]))
        ->assertStatus(422)->assertJsonPath('code', 'change_for_too_low');
    Shop::checkout($this->a, Shop::deliveryPayload($token, $this->zone, ['fulfillment' => ['address' => ['street' => null]]]))
        ->assertStatus(422)->assertJsonStructure(['details' => ['fields' => ['fulfillment.address.street']]]);
});

it('cliente é identificado pelo celular dentro do tenant (sem duplicar)', function () {
    foreach (['(82) 99876-5432', '+55 82 99876 5432'] as $phone) {
        $token = Shop::newCart($this->a);
        Shop::addSoda($this->fa, $token);
        Shop::checkout($this->a, Shop::pickupPayload($token, ['customer' => ['phone' => $phone]]))->assertCreated();
    }

    expect(inTenant($this->a, fn () => Customer::query()->count()))->toBe(1)
        ->and(inTenant($this->b, fn () => Customer::query()->count()))->toBe(0);
});

it('SNAPSHOT: alterar produto e preços depois não muda o pedido antigo', function () {
    $token = Shop::newCart($this->a);
    Shop::addPizza($this->fa, $token, 1, ['Portuguesa' => 1]);
    $tracking = Shop::checkout($this->a, Shop::pickupPayload($token))->json('data.tracking_token');

    inTenant($this->a, function () {
        $this->fa->pizza->update(['name' => 'Pizza Nova']);
        $this->fa->flavor['Portuguesa']->update(['name' => 'Portuguesa Especial']);
        $this->fa->flavor['Portuguesa']->variantPrices()->update(['price' => '99.00']);
        $this->fa->pizza->delete(); // arquivado
    });

    $this->getJson(tenantUrl($this->a, "orders/track/{$tracking}"))->assertOk()
        ->assertJsonPath('data.items.0.name', 'Pizza')
        ->assertJsonPath('data.items.0.options.0.name', 'Portuguesa')
        ->assertJsonPath('data.items.0.unit_price', '45.01');
});

it('IDEMPOTÊNCIA: chave reservada por requisição ainda em andamento responde 409 sem criar pedido', function () {
    $token = Shop::newCart($this->a);
    Shop::addSoda($this->fa, $token);
    $payload = Shop::pickupPayload($token);
    inTenant($this->a, fn () => IdempotencyKey::query()->create([
        'key' => 'chave-concorrente-12345', 'request_hash' => hash('sha256', (string) json_encode((new CheckoutRequest)->merge($payload)->all())),
        'status_code' => 0, 'response_json' => [],
    ]));

    Shop::checkout($this->a, $payload, 'chave-concorrente-12345')->assertStatus(409);
    expect(inTenant($this->a, fn () => Order::query()->count()))->toBe(0);
});
