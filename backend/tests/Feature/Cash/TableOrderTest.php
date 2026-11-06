<?php

use App\Modules\Cash\Models\DiningTable;
use App\Modules\Cash\Models\Tab;
use App\Modules\Identity\Models\Role;
use App\Modules\Platform\Models\Subscription;
use Illuminate\Testing\TestResponse;
use Tests\Support\CatalogFixture;
use Tests\Support\Shop;

beforeEach(function () {
    $this->a = makeTenant('tenant-a');
    $this->b = makeTenant('tenant-b');
    $this->fa = CatalogFixture::create($this->a);
    CatalogFixture::create($this->b);
    withCashbook($this->a);
    withCashbook($this->b);
    $this->owner = makeMember($this->a);
    $this->session = merchantLogin($this->a, $this->owner);
    $this->waiter = merchantLogin($this->a, makeMember($this->a, role: Role::WAITER, branch: $this->fa->branch));
    $this->branch = $this->fa->branch->public_id;
});

function salao(object $t, string $method, string $path, array $body = [], ?string $session = null): TestResponse
{
    return withSession($session ?? $t->session)->json($method, tenantUrl($t->a, 'merchant/cash/'.$path), $body);
}

/** Cria Mesa 1..3 e devolve o código do QR da Mesa 1. */
function mesas(object $t): string
{
    $list = salao($t, 'POST', 'dining-tables', ['branch' => $t->branch, 'prefix' => 'Mesa', 'from' => 1, 'to' => 3])->assertCreated()->json('data');
    expect(array_column($list, 'label'))->toBe(['Mesa 1', 'Mesa 2', 'Mesa 3'])->and($list[0]['qr_url'])->toContain('/mesa/');

    return inTenant($t->a, fn () => DiningTable::query()->where('label', 'Mesa 1')->value('qr_token'));
}

function qrOrder(object $t, string $token, int $pizzas = 1): TestResponse
{
    $cart = Shop::newCart($t->a);
    Shop::addPizza($t->fa, $cart, $pizzas)->assertCreated();

    return test()->postJson(tenantUrl($t->a, "tables/{$token}/requests"), ['type' => 'order', 'cart_token' => $cart, 'customer_name' => 'Rita', 'notes' => 'sem cebola']);
}

it('QR: cliente pede, garçom confirma e manda à cozinha; a conta da mesa abre sozinha', function () {
    $token = mesas($this);
    $this->getJson(tenantUrl($this->a, "tables/{$token}"))->assertOk()->assertJsonPath('data.label', 'Mesa 1');

    $sent = qrOrder($this, $token, 2)->assertCreated()->assertJsonPath('data.status', 'pending');
    $id = $sent->json('data.id');
    $view = $sent->json('data.view_token');
    expect($sent->json('data.summary'))->toContain('2× Pizza (Média, Calabresa)');
    $this->getJson(tenantUrl($this->a, "table-requests/{$id}?t={$view}"))->assertOk()->assertJsonPath('data.status', 'pending');
    $this->getJson(tenantUrl($this->a, "table-requests/{$id}?t=errado"))->assertNotFound();

    // Nada na cozinha ainda: o garçom vê o pedido aguardando.
    $floor = salao($this, 'GET', "floor?branch={$this->branch}", session: $this->waiter)->assertOk();
    expect($floor->json('data.requests.0.table'))->toBe('Mesa 1')->and($floor->json('data.tables.0.pending'))->toBe(1)
        ->and(inTenant($this->a, fn () => Tab::query()->count()))->toBe(0);

    salao($this, 'POST', "tab-requests/{$id}/accept", ['send_to_kitchen' => true], $this->waiter)->assertOk()->assertJsonPath('data.kitchen_batch', 1);
    $tab = inTenant($this->a, fn () => Tab::query()->with('items')->sole());
    expect($tab->label)->toBe('Mesa 1')->and($tab->customer_name)->toBe('Rita')
        ->and($tab->items->sum('quantity'))->toBe(2)->and($tab->items->first()->unit_price)->toBe('40.00')
        ->and($tab->items->first()->notes)->toContain('sem cebola')->and($tab->items->first()->sent_at)->not->toBeNull();
    $this->getJson(tenantUrl($this->a, "table-requests/{$id}?t={$view}"))->assertJsonPath('data.status', 'accepted');
    salao($this, 'POST', "tab-requests/{$id}/accept", [], $this->waiter)->assertStatus(409);

    // Segundo pedido da mesma mesa cai na mesma conta.
    $second = qrOrder($this, $token)->json('data.id');
    salao($this, 'POST', "tab-requests/{$second}/accept", [], $this->waiter)->assertOk();
    expect(inTenant($this->a, fn () => Tab::query()->count()))->toBe(1);
});

it('garçom recusa com motivo; o cliente vê; carrinho enviado não vai de novo', function () {
    $token = mesas($this);
    $cart = Shop::newCart($this->a);
    Shop::addSoda($this->fa, $cart)->assertCreated();
    $sent = $this->postJson(tenantUrl($this->a, "tables/{$token}/requests"), ['type' => 'order', 'cart_token' => $cart])->assertCreated();
    $this->postJson(tenantUrl($this->a, "tables/{$token}/requests"), ['type' => 'order', 'cart_token' => $cart])->assertStatus(404);

    salao($this, 'POST', "tab-requests/{$sent->json('data.id')}/reject", ['reason' => 'Mesa vazia'], $this->waiter)->assertOk();
    $this->getJson(tenantUrl($this->a, "table-requests/{$sent->json('data.id')}?t={$sent->json('data.view_token')}"))
        ->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.reason', 'Mesa vazia');
});

it('chamar garçom e pedir a conta: repetir não duplica; conta pedida aparece na mesa', function () {
    $token = mesas($this);
    qrOrder($this, $token);
    $first = salao($this, 'GET', "floor?branch={$this->branch}")->json('data.requests.0.id');
    salao($this, 'POST', "tab-requests/{$first}/accept")->assertOk();

    $call = $this->postJson(tenantUrl($this->a, "tables/{$token}/requests"), ['type' => 'waiter'])->assertCreated();
    $this->postJson(tenantUrl($this->a, "tables/{$token}/requests"), ['type' => 'waiter'])->assertOk()->assertJsonPath('data.id', $call->json('data.id'))
        ->assertJsonPath('data.view_token', null);
    $bill = $this->postJson(tenantUrl($this->a, "tables/{$token}/requests"), ['type' => 'bill'])->assertCreated()->json('data.id');
    salao($this, 'POST', "tab-requests/{$bill}/accept", [], $this->waiter)->assertOk();
    $row = collect(salao($this, 'GET', "floor?branch={$this->branch}", session: $this->waiter)->json('data.tables'))->firstWhere('label', 'Mesa 1');
    expect($row['tab']['bill_requested_at'])->not->toBeNull();
    expect(collect(salao($this, 'GET', "tabs?branch={$this->branch}")->json('data'))->first()['bill_requested_at'])->not->toBeNull();
});

it('pedido não confirmado expira; limite de pedidos aguardando por mesa', function () {
    $token = mesas($this);
    $id = qrOrder($this, $token)->json('data.id');
    $this->travel(31)->minutes();
    salao($this, 'POST', "tab-requests/{$id}/accept", [], merchantLogin($this->a, $this->owner))->assertStatus(409)->assertJsonPath('message', 'Este pedido expirou.');
    $this->travelBack();

    foreach (range(1, 5) as $i) {
        qrOrder($this, $token)->assertCreated();
    }
    qrOrder($this, $token)->assertStatus(429)->assertJsonPath('code', 'too_many_requests');
});

it('garçom lança e manda à cozinha, mas não fecha nem mexe no caixa', function () {
    mesas($this);
    $tab = salao($this, 'POST', 'tabs', ['branch' => $this->branch, 'label' => 'Mesa 2'], $this->waiter)->assertCreated()->json('data.id');
    salao($this, 'GET', "pos/catalog?branch={$this->branch}", session: $this->waiter)->assertOk();
    salao($this, 'POST', "tabs/{$tab}/items", ['items' => [['product_id' => $this->fa->soda->public_id, 'variant_id' => $this->fa->sodaVariant->public_id, 'quantity' => 2]]], $this->waiter)->assertCreated();
    salao($this, 'POST', "tabs/{$tab}/send", session: $this->waiter)->assertOk()->assertJsonPath('data.batch', 1);
    salao($this, 'POST', "tabs/{$tab}/bill", session: $this->waiter)->assertOk();

    salao($this, 'POST', "tabs/{$tab}/close", ['payments' => [['method' => 'cash', 'amount' => '13,00']]], $this->waiter)->assertForbidden();
    salao($this, 'GET', "session?branch={$this->branch}", session: $this->waiter)->assertForbidden();
    salao($this, 'POST', 'dining-tables', ['branch' => $this->branch, 'label' => 'Mesa 9'], $this->waiter)->assertForbidden();
});

it('QR só com Cardápio + Mesas; outro restaurante não acha a mesa', function () {
    $token = mesas($this);
    $this->getJson(tenantUrl($this->b, "tables/{$token}"))->assertNotFound();

    inTenant($this->a, fn () => Subscription::query()->latest('id')->first()->forceFill(['modules_json' => ['pos', 'tabs']])->save());
    $this->getJson(tenantUrl($this->a, "tables/{$token}"))->assertForbidden()->assertJsonPath('code', 'feature_unavailable');
});
