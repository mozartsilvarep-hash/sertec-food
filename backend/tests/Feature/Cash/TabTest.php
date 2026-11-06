<?php

use App\Modules\Catalog\Models\StockMovement;
use App\Modules\Identity\Models\Role;
use App\Modules\Ordering\Models\Order;
use Illuminate\Support\Str;
use Tests\Support\CatalogFixture;

beforeEach(function () {
    $this->a = makeTenant('tenant-a');
    $this->b = makeTenant('tenant-b');
    $this->fa = CatalogFixture::create($this->a);
    CatalogFixture::create($this->b);
    withCashbook($this->a);
    withCashbook($this->b);
    $this->owner = makeMember($this->a);
    $this->session = merchantLogin($this->a, $this->owner);
    $this->branch = $this->fa->branch->public_id;
});

function tabApi(object $t, string $method, string $path, array $body = [], ?string $session = null, ?string $key = null)
{
    $req = withSession($session ?? $t->session);
    if ($key !== null) {
        $req = $req->withHeader('Idempotency-Key', $key);
    }

    return $req->json($method, tenantUrl($t->a, 'merchant/cash/'.$path), $body);
}

function tabPizza(object $t, ?string $notes = null): array
{
    return ['product_id' => $t->fa->pizza->public_id, 'variant_id' => $t->fa->medium->public_id, 'options' => $t->fa->pick(['Calabresa' => 1]), 'quantity' => 1, 'notes' => $notes];
}

function tabSoda(object $t, int $qty = 1): array
{
    return ['product_id' => $t->fa->soda->public_id, 'variant_id' => $t->fa->sodaVariant->public_id, 'quantity' => $qty];
}

function openTab(object $t, string $label = 'Mesa 4', int $people = 1): string
{
    return tabApi($t, 'POST', 'tabs', ['branch' => $t->branch, 'label' => $label, 'people' => $people])->assertCreated()->json('data.id');
}

it('mesa: lança, envia à cozinha em lotes, remove com motivo e fecha como venda com taxa de serviço', function () {
    withSession($this->session)->postJson(tenantUrl($this->a, 'merchant/cash/session'), ['branch' => $this->branch, 'opening_amount' => '0'])->assertCreated();
    $tab = openTab($this, 'Mesa 4', 3);
    tabApi($this, 'POST', 'tabs', ['branch' => $this->branch, 'label' => ' mesa  4 '])->assertStatus(409)->assertJsonPath('code', 'tab_already_open');

    tabApi($this, 'POST', "tabs/{$tab}/items", ['items' => [tabPizza($this, 'sem cebola'), ['description' => 'Couvert', 'unit_price' => '5,00', 'quantity' => 2]]])
        ->assertCreated()->assertJsonPath('data.subtotal', '50.00')->assertJsonPath('data.pending_kitchen', 2)
        ->assertJsonPath('data.items.0.detail', 'Média, Calabresa');
    tabApi($this, 'POST', "tabs/{$tab}/send")->assertOk()->assertJsonPath('data.batch', 1)->assertJsonPath('data.count', 2);
    $res = tabApi($this, 'POST', "tabs/{$tab}/items", ['items' => [tabSoda($this, 2)]])->assertCreated();
    tabApi($this, 'POST', "tabs/{$tab}/send")->assertOk()->assertJsonPath('data.batch', 2)->assertJsonPath('data.count', 1);

    // Item ainda não enviado some; enviado precisa de motivo e fica riscado.
    $extra = collect(tabApi($this, 'POST', "tabs/{$tab}/items", ['items' => [tabSoda($this)]])->json('data.items'))->last()['id'];
    tabApi($this, 'POST', "tabs/{$tab}/items/{$extra}/void")->assertOk();
    $soda = $res->json('data.items.2.id');
    tabApi($this, 'POST', "tabs/{$tab}/items/{$soda}/void")->assertStatus(422);
    $detail = tabApi($this, 'POST', "tabs/{$tab}/items/{$soda}/void", ['reason' => 'Cliente desistiu'])->assertOk();
    expect($detail->json('data.items'))->toHaveCount(3)->and($detail->json('data.items.2.void_reason'))->toBe('Cliente desistiu')
        ->and($detail->json('data.subtotal'))->toBe('50.00');

    // Fechar: 50 + 10% = 55, dividido em duas formas.
    tabApi($this, 'POST', "tabs/{$tab}/close", ['service_percent' => 10, 'payments' => [['method' => 'pix', 'amount' => '30,00']]], key: (string) Str::uuid())
        ->assertStatus(422)->assertJsonPath('details.total', '55.00');
    $key = (string) Str::uuid();
    $body = ['service_percent' => 10, 'payments' => [['method' => 'pix', 'amount' => '30,00'], ['method' => 'credit', 'amount' => '25,00']]];
    $close = tabApi($this, 'POST', "tabs/{$tab}/close", $body, key: $key)->assertCreated()->assertJsonPath('data.total', '55.00')->assertJsonPath('data.status', 'completed');
    // Repetir com a mesma chave devolve a mesma venda.
    tabApi($this, 'POST', "tabs/{$tab}/close", $body, key: $key)->assertCreated()->assertJsonPath('data.order_id', $close->json('data.order_id'));

    $order = inTenant($this->a, fn () => Order::query()->where('public_id', $close->json('data.order_id'))->with('items.options')->sole());
    expect($order->table_label)->toBe('Mesa 4')->and($order->items)->toHaveCount(2)
        ->and($order->items->firstWhere('notes', 'sem cebola')?->options->pluck('option_name_snapshot')->all())->toContain('Calabresa');
    withSession($this->session)->getJson(tenantUrl($this->a, "merchant/orders/{$order->public_id}"))->assertJsonPath('data.table_label', 'Mesa 4');

    tabApi($this, 'GET', "tabs/{$tab}")->assertJsonPath('data.status', 'closed')->assertJsonPath('data.order_id', $order->public_id);
    tabApi($this, 'POST', "tabs/{$tab}/items", ['items' => [tabSoda($this)]])->assertStatus(409)->assertJsonPath('code', 'tab_not_open');
    expect(tabApi($this, 'GET', 'tabs?branch='.$this->branch)->json('data'))->toBe([]);
    openTab($this, 'Mesa 4'); // fechada: pode abrir de novo
});

it('estoque: baixa ao lançar, devolve ao remover e fica ligado ao pedido no fechamento', function () {
    withSession($this->session)->postJson(tenantUrl($this->a, 'merchant/cash/session'), ['branch' => $this->branch, 'opening_amount' => '0'])->assertCreated();
    withSession($this->session)->putJson(tenantUrl($this->a, 'merchant/stock/'.$this->fa->soda->public_id), ['branch' => $this->branch, 'tracked' => true, 'quantity' => 2])->assertOk();
    $tab = openTab($this);
    tabApi($this, 'POST', "tabs/{$tab}/items", ['items' => [tabSoda($this, 3)]])->assertStatus(422)->assertJsonPath('code', 'out_of_stock');
    $items = tabApi($this, 'POST', "tabs/{$tab}/items", ['items' => [tabSoda($this, 2)]])->assertCreated()->json('data.items');
    // Acabou: a última lata está na mesa.
    $other = openTab($this, 'Mesa 5');
    tabApi($this, 'POST', "tabs/{$other}/items", ['items' => [tabSoda($this)]])->assertStatus(422);

    tabApi($this, 'POST', "tabs/{$tab}/items/{$items[0]['id']}/void")->assertOk();
    tabApi($this, 'POST', "tabs/{$other}/items", ['items' => [tabSoda($this)]])->assertCreated();
    $close = tabApi($this, 'POST', "tabs/{$other}/close", ['payments' => [['method' => 'cash', 'amount' => '6,50']]], key: (string) Str::uuid())->assertCreated();

    $orderId = inTenant($this->a, fn () => Order::query()->where('public_id', $close->json('data.order_id'))->value('id'));
    expect(inTenant($this->a, fn () => StockMovement::query()->where('order_id', $orderId)->sum('quantity')))->toEqual(-1);
});

it('cancelar conta: com itens na cozinha só gerente; devolve o estoque', function () {
    withSession($this->session)->putJson(tenantUrl($this->a, 'merchant/stock/'.$this->fa->soda->public_id), ['branch' => $this->branch, 'tracked' => true, 'quantity' => 5])->assertOk();
    $tab = openTab($this);
    tabApi($this, 'POST', "tabs/{$tab}/items", ['items' => [tabSoda($this, 2)]])->assertCreated();
    tabApi($this, 'POST', "tabs/{$tab}/send")->assertOk();

    $attendant = merchantLogin($this->a, makeMember($this->a, role: Role::ATTENDANT, branch: $this->fa->branch));
    tabApi($this, 'GET', "tabs/{$tab}", session: $attendant)->assertOk();
    tabApi($this, 'POST', "tabs/{$tab}/cancel", ['reason' => 'Cliente foi embora'], $attendant)->assertForbidden();
    tabApi($this, 'POST', "tabs/{$tab}/cancel", ['reason' => 'Cliente foi embora'])->assertNoContent();

    $stock = collect(withSession($this->session)->getJson(tenantUrl($this->a, "merchant/stock?branch={$this->branch}"))->json('data.products'))->firstWhere('id', $this->fa->soda->public_id);
    expect($stock['quantity'])->toBe(5);
});

it('fechar exige caixa aberto; outro restaurante e a cozinha não acessam', function () {
    $tab = openTab($this);
    tabApi($this, 'POST', "tabs/{$tab}/items", ['items' => [tabSoda($this)]])->assertCreated();
    tabApi($this, 'POST', "tabs/{$tab}/close", ['payments' => [['method' => 'cash', 'amount' => '6,50']]], key: (string) Str::uuid())
        ->assertStatus(409)->assertJsonPath('code', 'cash_closed');

    $ownerB = merchantLogin($this->b, makeMember($this->b));
    withSession($ownerB)->getJson(tenantUrl($this->b, "merchant/cash/tabs/{$tab}"))->assertNotFound();
    $kitchen = merchantLogin($this->a, makeMember($this->a, role: Role::KITCHEN, branch: $this->fa->branch));
    tabApi($this, 'GET', "tabs/{$tab}", session: $kitchen)->assertForbidden();
});
