<?php

use App\Modules\Identity\Models\Role;
use App\Modules\Ordering\Http\Controllers\ReportController;
use App\Modules\Ordering\Models\Order;
use App\Modules\Platform\Models\AuditLog;
use Tests\Support\CatalogFixture;
use Tests\Support\Shop;

beforeEach(function () {
    $this->a = makeTenant('tenant-a');
    $this->b = makeTenant('tenant-b');
    $this->fa = CatalogFixture::create($this->a);
    $this->fb = CatalogFixture::create($this->b);
    $this->session = merchantLogin($this->a, makeMember($this->a));
});

function shopOrder(CatalogFixture $f, string $name, string $phone, string $method = 'cash', int $pizzas = 1): string
{
    $token = Shop::newCart($f->tenant);
    Shop::addPizza($f, $token, $pizzas);

    return (string) Shop::checkout($f->tenant, Shop::pickupPayload($token, [
        'customer' => ['name' => $name, 'phone' => $phone],
        'payment' => ['method' => $method],
    ]))->assertCreated()->json('data.order_id');
}

function setStatus(object $t, string $order, array $statuses): void
{
    foreach ($statuses as $s) {
        $extra = in_array($s, ['rejected', 'cancelled'], true) ? ['reason' => 'other'] : [];
        withSession($t->session)->patchJson(tenantUrl($t->a, "merchant/orders/{$order}/status"), ['status' => $s, ...$extra])->assertOk();
    }
}

it('histórico: busca por número, nome e telefone; filtros de período e pagamento', function () {
    $o1 = shopOrder($this->fa, 'Maria Souza', '(82) 99876-5432');
    $o2 = shopOrder($this->fa, 'José Lima', '(82) 98888-1111', 'card_on_delivery');
    shopOrder($this->fb, 'Maria Souza', '(82) 99876-5432'); // outro tenant

    $list = fn (string $qs) => withSession($this->session)->getJson(tenantUrl($this->a, "merchant/orders?view=all&{$qs}"))->assertOk()->json('data.*.id');

    expect($list('q=maria'))->toBe([$o1])
        ->and($list('q=8888'))->toBe([$o2])
        ->and($list('q=%232'))->toBe([$o2])
        ->and($list('payment_method=card_on_delivery'))->toBe([$o2])
        ->and($list('q=%25'))->toBe([]) // curinga do LIKE é escapado
        ->and($list('from='.now('America/Sao_Paulo')->toDateString()))->toHaveCount(2)
        ->and($list('to='.now('America/Sao_Paulo')->subDay()->toDateString()))->toBe([]);

    withSession($this->session)->getJson(tenantUrl($this->a, 'merchant/orders?from=2026-10-10&to=2026-01-01'))->assertStatus(422);
});

it('resumo: faturamento só de concluídos, por forma de pagamento, ticket médio e cancelados', function () {
    $a = shopOrder($this->fa, 'Ana', '(82) 99999-0001');             // 40,00 dinheiro
    $b = shopOrder($this->fa, 'Bia', '(82) 99999-0002', 'card_on_delivery', 2); // 80,00 cartão
    $c = shopOrder($this->fa, 'Caio', '(82) 99999-0003');            // cancelado
    shopOrder($this->fa, 'Duda', '(82) 99999-0004');                 // em andamento
    shopOrder($this->fb, 'Outro', '(82) 99999-0005');                // tenant B

    setStatus($this, $a, ['accepted', 'preparing', 'ready', 'completed']);
    setStatus($this, $b, ['accepted', 'preparing', 'ready', 'completed']);
    setStatus($this, $c, ['rejected']);

    withSession($this->session)->getJson(tenantUrl($this->a, 'merchant/reports/summary'))->assertOk()
        ->assertJsonPath('data.orders', 4)
        ->assertJsonPath('data.completed', 2)
        ->assertJsonPath('data.cancelled', 1)
        ->assertJsonPath('data.in_progress', 1)
        ->assertJsonPath('data.revenue', '120.00')
        ->assertJsonPath('data.average_ticket', '60.00')
        ->assertJsonPath('data.by_payment_method', [
            ['method' => 'card_on_delivery', 'label' => 'Cartão na entrega', 'orders' => 1, 'total' => '80.00'],
            ['method' => 'cash', 'label' => 'Dinheiro', 'orders' => 1, 'total' => '40.00'],
        ])
        // Mais vendidos (só concluídos), dia, horário e tipo de entrega.
        ->assertJsonPath('data.top_products.0', ['name' => 'Pizza', 'quantity' => 3, 'total' => '120.00'])
        ->assertJsonCount(1, 'data.top_products')
        ->assertJsonPath('data.by_day.0', ['date' => now('America/Sao_Paulo')->toDateString(), 'orders' => 2, 'revenue' => '120.00'])
        ->assertJsonPath('data.by_hour.0.orders', 2)
        ->assertJsonPath('data.by_fulfillment', ['delivery' => 0, 'pickup' => 2, 'counter' => 0]);
});

it('CSV: separador ;, BOM, valores com vírgula, fórmulas neutralizadas e sem telefone; exportação auditada', function () {
    shopOrder($this->fa, '=HYPERLINK("http://mal")', '(82) 99876-5432');

    $response = withSession($this->session)->get(tenantUrl($this->a, 'merchant/reports/orders.csv'))->assertOk();
    $csv = $response->streamedContent();

    expect($response->headers->get('Content-Type'))->toContain('text/csv')
        ->and($csv)->toStartWith("\xEF\xBB\xBFNúmero;Data;")
        ->and($csv)->toContain(';"\'=HYPERLINK(')
        ->and($csv)->toContain(';40,00;')
        ->and($csv)->toContain(';Pendente;')
        ->and($csv)->not->toContain('99876')
        ->and(inTenant($this->a, fn () => AuditLog::query()->where('action', 'reports.orders_exported')->count()))->toBe(1);

    expect(ReportController::cell('-1+2'))->toBe("'-1+2")
        ->and(ReportController::cell('@SUM(A1)'))->toBe("'@SUM(A1)")
        ->and(ReportController::cell('Maria'))->toBe('Maria');
});

it('relatórios exigem reports.view (atendente não tem)', function () {
    $session = merchantLogin($this->a, makeMember($this->a, role: Role::ATTENDANT));
    withSession($session)->getJson(tenantUrl($this->a, 'merchant/reports/summary'))->assertForbidden();
});

it('pedido aguardando pagamento online não entra no histórico nem no resumo', function () {
    shopOrder($this->fa, 'Ana', '(82) 99999-0001');
    inTenant($this->a, fn () => Order::query()->update(['status' => 'awaiting_payment']));

    withSession($this->session)->getJson(tenantUrl($this->a, 'merchant/orders?view=all'))->assertJsonCount(0, 'data');
    withSession($this->session)->getJson(tenantUrl($this->a, 'merchant/reports/summary'))->assertJsonPath('data.orders', 0);
});
