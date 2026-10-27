<?php

use App\Modules\Cash\Models\CashEntry;
use App\Modules\Identity\Models\Role;
use Tests\Support\CatalogFixture;
use Tests\Support\FakeMercadoPago;
use Tests\Support\Pay;
use Tests\Support\Shop;

beforeEach(function () {
    $this->a = makeTenant('tenant-a');
    $this->b = makeTenant('tenant-b');
    $this->fa = CatalogFixture::create($this->a);
    $this->fb = CatalogFixture::create($this->b);
    withCashbook($this->a);
    withCashbook($this->b);
    $this->owner = makeMember($this->a);
    $this->session = merchantLogin($this->a, $this->owner);
    $this->branch = $this->fa->branch->public_id;
});

function cash(object $t, string $method, string $path, array $body = [], ?string $session = null)
{
    return withSession($session ?? $t->session)->{$method.'Json'}(tenantUrl($t->a, 'merchant/cash/'.$path), $body);
}

it('dia de caixa: abre, vende no balcão com troco, sangria, despesa e fecha conferido', function () {
    $attendant = merchantLogin($this->a, makeMember($this->a, role: Role::ATTENDANT, branch: $this->fa->branch));
    cash($this, 'get', "session?branch={$this->branch}", session: $attendant)->assertOk()->assertJsonPath('data', null);
    $open = cash($this, 'post', 'session', ['branch' => $this->branch, 'opening_amount' => '100,00'], $attendant)->assertCreated();
    $sessionId = $open->json('data.id');
    cash($this, 'post', 'session', ['branch' => $this->branch, 'opening_amount' => '0'], $attendant)->assertStatus(409)->assertJsonPath('code', 'cash_already_open');

    // 2 refrigerantes (preço do servidor) + bolo avulso − desconto, pago em dinheiro.
    cash($this, 'post', 'sales', [
        'branch' => $this->branch, 'method' => 'cash', 'discount' => '3,00', 'received' => '50,00',
        'items' => [
            ['product_id' => $this->fa->soda->public_id, 'quantity' => 2, 'unit_price' => '0.01'],
            ['description' => 'Bolo de pote', 'unit_price' => '10,00', 'quantity' => 1],
        ],
    ], $attendant)->assertCreated()
        ->assertJsonPath('data.amount', '20.00')
        ->assertJsonPath('data.change', '30.00')
        ->assertJsonPath('data.category_label', 'Venda no balcão')
        ->assertJsonPath('data.items.0', ['name' => 'Refrigerante lata', 'quantity' => 2, 'unit_price' => '6.50', 'total' => '13.00'])
        ->assertJsonPath('data.items.2.name', 'Desconto');
    cash($this, 'post', 'sales', ['branch' => $this->branch, 'method' => 'pix', 'items' => [['description' => 'Torta', 'unit_price' => '35', 'quantity' => 1]]], $attendant)->assertCreated();

    // Sangria é sempre em dinheiro, mesmo que venha outra forma.
    cash($this, 'post', 'entries', ['branch' => $this->branch, 'category' => 'withdrawal', 'method' => 'pix', 'amount' => '30', 'description' => 'Depósito'], $attendant)
        ->assertCreated()->assertJsonPath('data.method', 'cash')->assertJsonPath('data.type', 'out');
    cash($this, 'post', 'entries', ['branch' => $this->branch, 'category' => 'supplier', 'method' => 'pix', 'amount' => '15,00', 'description' => 'Leite condensado'], $attendant)->assertCreated();
    cash($this, 'post', 'entries', ['branch' => $this->branch, 'category' => 'order', 'method' => 'cash', 'amount' => '1'], $attendant)->assertStatus(422);

    $current = cash($this, 'get', "session?branch={$this->branch}", session: $attendant)->assertOk();
    // Gaveta: 100 + 20 (dinheiro) − 30 (sangria) = 90; Pix não entra na gaveta.
    expect($current->json('data.expected_amount'))->toBe('90.00')
        ->and(collect($current->json('data.by_method'))->firstWhere('method', 'pix'))->toMatchArray(['in' => '35.00', 'out' => '15.00']);

    $closed = cash($this, 'post', "session/{$sessionId}/close", ['counted_amount' => '88,50', 'notes' => 'Faltou troco'], $attendant)->assertOk();
    expect($closed->json('data'))->toMatchArray(['open' => false, 'expected_amount' => '90.00', 'counted_amount' => '88.50', 'difference' => '-1.50']);

    // Caixa fechado: não aceita lançamentos.
    cash($this, 'post', 'sales', ['branch' => $this->branch, 'method' => 'cash', 'items' => [['description' => 'X', 'unit_price' => '1', 'quantity' => 1]]], $attendant)
        ->assertStatus(409)->assertJsonPath('code', 'cash_closed');
    cash($this, 'post', "session/{$sessionId}/close", ['counted_amount' => '0'], $attendant)->assertStatus(409);
});

it('resumo do período, histórico de caixas e CSV seguro (gerência)', function () {
    $s = cash($this, 'post', 'session', ['opening_amount' => '50'])->assertCreated()->json('data.id');
    cash($this, 'post', 'sales', ['method' => 'credit', 'items' => [['description' => '=HYPERLINK("x")', 'unit_price' => '40', 'quantity' => 1]]])->assertCreated();
    cash($this, 'post', 'entries', ['category' => 'bills', 'method' => 'pix', 'amount' => '12,30', 'description' => 'Luz'])->assertCreated();
    cash($this, 'post', "session/{$s}/close", ['counted_amount' => '50'])->assertOk();

    $sum = cash($this, 'get', 'summary?from='.now($this->a->timezone)->format('Y-m-d'))->assertOk()->json('data');
    expect($sum)->toMatchArray(['in' => '40.00', 'out' => '12.30', 'balance' => '27.70'])
        ->and(collect($sum['by_category'])->pluck('label')->all())->toBe(['Venda no balcão', 'Contas (água, luz, aluguel…)'])
        ->and($sum['by_day'][0])->toMatchArray(['in' => '40.00', 'out' => '12.30']);
    cash($this, 'get', 'sessions')->assertOk()->assertJsonPath('data.0.difference', '0.00');

    $csv = withSession($this->session)->get(tenantUrl($this->a, 'merchant/cash/entries.csv'));
    $csv->assertOk();
    $body = $csv->streamedContent();
    expect($body)->toStartWith("\xEF\xBB\xBF")->toContain('Venda no balcão')->toContain('12,30');
});

it('estorno de lançamento: sai das contas, exige motivo; caixa fechado não estorna', function () {
    $s = cash($this, 'post', 'session', ['opening_amount' => '0'])->json('data.id');
    $sale = cash($this, 'post', 'sales', ['method' => 'cash', 'items' => [['description' => 'Bolo', 'unit_price' => '20', 'quantity' => 1]]])->json('data.id');

    cash($this, 'post', "entries/{$sale}/void", ['reason' => ''])->assertStatus(422);
    cash($this, 'post', "entries/{$sale}/void", ['reason' => 'Lançado em dobro'])->assertOk()->assertJsonPath('data.voided', true);
    cash($this, 'post', "entries/{$sale}/void", ['reason' => 'De novo'])->assertStatus(409);
    expect(cash($this, 'get', 'session')->json('data.expected_amount'))->toBe('0.00');

    $other = cash($this, 'post', 'sales', ['method' => 'cash', 'items' => [['description' => 'Bolo', 'unit_price' => '20', 'quantity' => 1]]])->json('data.id');
    cash($this, 'post', "session/{$s}/close", ['counted_amount' => '20'])->assertOk();
    cash($this, 'post', "entries/{$other}/void", ['reason' => 'Tarde demais'])->assertStatus(409)->assertJsonPath('code', 'cash_session_closed');
});

it('permissões, unidade da atendente, outro restaurante e plano sem caixa', function () {
    $kitchen = merchantLogin($this->a, makeMember($this->a, role: Role::KITCHEN));
    cash($this, 'get', 'session', session: $kitchen)->assertForbidden();

    $other = makeBranch($this->a, ['name' => 'Bairro']);
    $attendant = merchantLogin($this->a, makeMember($this->a, role: Role::ATTENDANT, branch: $this->fa->branch));
    cash($this, 'post', 'session', ['branch' => $other->public_id, 'opening_amount' => '0'], $attendant)->assertForbidden();
    cash($this, 'post', 'session', ['branch' => $this->branch, 'opening_amount' => '0'], $attendant)->assertCreated();
    $sale = cash($this, 'post', 'sales', ['branch' => $this->branch, 'method' => 'cash', 'items' => [['description' => 'X', 'unit_price' => '5', 'quantity' => 1]]], $attendant)->json('data.id');
    cash($this, 'post', "entries/{$sale}/void", ['reason' => 'Erro'], $attendant)->assertForbidden();
    cash($this, 'get', 'summary', session: $attendant)->assertForbidden();
    // Atendente só lista o caixa aberto da unidade dela.
    expect(cash($this, 'get', 'entries', session: $attendant)->json('data'))->toHaveCount(1);

    // Lançamento de outro restaurante: não existe aqui.
    $foreign = inTenant($this->b, fn () => CashEntry::query()->create(['branch_id' => $this->fb->branch->id, 'type' => 'in', 'category' => 'other_in', 'method' => 'cash', 'amount' => '1.00', 'occurred_at' => now()]));
    cash($this, 'post', "entries/{$foreign->public_id}/void", ['reason' => 'x'])->assertNotFound();

    $c = makeTenant('tenant-c');
    withCashbook($c, enabled: false);
    $owner = makeMember($c);
    withSession(merchantLogin($c, $owner))->getJson(tenantUrl($c, 'merchant/cash/session'))->assertForbidden()->assertJsonPath('code', 'feature_unavailable');
});

it('pedido concluído entra sozinho no caixa (uma vez) e o estorno online sai', function () {
    $mp = FakeMercadoPago::install();
    $mp->connect($this->a);
    cash($this, 'post', 'session', ['opening_amount' => '0'])->assertCreated();

    $token = Shop::newCart($this->a);
    Shop::addSoda($this->fa, $token, 2);
    $order = Shop::checkout($this->a, Shop::pickupPayload($token))->json('data.order_id');
    foreach (['accepted', 'preparing', 'ready', 'completed'] as $status) {
        withSession($this->session)->patchJson(tenantUrl($this->a, "merchant/orders/{$order}/status"), ['status' => $status])->assertOk();
    }
    $entry = inTenant($this->a, fn () => CashEntry::query()->where('category', 'order')->sole());
    expect($entry->amount)->toBe('13.00')->and($entry->method->value)->toBe('cash')->and($entry->cash_session_id)->not->toBeNull();
    expect(cash($this, 'get', 'session')->json('data.expected_amount'))->toBe('13.00');

    // Pix pago, concluído e depois reembolsado.
    $placed = Pay::order($this->fa, 'pix');
    $mp->set($mp->lastPaymentId(), 'approved');
    Pay::webhook($this->a, $mp->lastPaymentId())->assertOk();
    foreach (['accepted', 'preparing', 'ready', 'completed'] as $status) {
        withSession($this->session)->patchJson(tenantUrl($this->a, "merchant/orders/{$placed['order_id']}/status"), ['status' => $status])->assertOk();
    }
    withSession($this->session)->postJson(tenantUrl($this->a, "merchant/orders/{$placed['order_id']}/refunds"), ['amount' => '10,00'])->assertCreated();
    $rows = inTenant($this->a, fn () => CashEntry::query()->where('order_id', Pay::orderModel($this->a, $placed['order_id'])->id)->orderBy('id')->get());
    expect($rows->pluck('category')->map->value->all())->toBe(['order', 'refund'])
        ->and($rows->pluck('method')->map->value->all())->toBe(['online', 'online'])
        ->and($rows[1]->amount)->toBe('10.00')->and($rows[1]->type)->toBe('out');
    // Pago online não mexe na gaveta.
    expect(cash($this, 'get', 'session')->json('data.expected_amount'))->toBe('13.00');
});
