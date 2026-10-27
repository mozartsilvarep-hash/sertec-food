<?php

use App\Modules\Cash\Models\CashEntry;
use App\Modules\Cash\Services\FinanceReport;
use App\Modules\Identity\Models\Role;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Tests\Support\CatalogFixture;

beforeEach(function () {
    $this->a = makeTenant('tenant-a');
    $this->b = makeTenant('tenant-b');
    $this->fa = CatalogFixture::create($this->a);
    CatalogFixture::create($this->b);
    withCashbook($this->a);
    withCashbook($this->b);
    $this->session = merchantLogin($this->a, makeMember($this->a));
    $this->branch = $this->fa->branch->public_id;
});

function fin(object $t, string $method, string $path, array $body = [], ?string $session = null)
{
    return withSession($session ?? $t->session)->{$method.'Json'}(tenantUrl($t->a, 'merchant/cash/'.$path), $body);
}

function finSale(object $t, string $method, string $amount): void
{
    $line = ['description' => 'Bolo', 'unit_price' => $amount, 'quantity' => 1];
    withSession($t->session)->withHeader('Idempotency-Key', (string) Str::uuid())
        ->postJson(tenantUrl($t->a, 'merchant/cash/pos/sales'), ['branch' => $t->branch, 'items' => [$line], 'payments' => [['method' => $method, 'amount' => $amount]]])
        ->assertCreated();
}

it('custo fixo proporcional aos dias do período', function () {
    $from = CarbonImmutable::parse('2026-11-01');
    expect(FinanceReport::prorata(300000, $from, $from->endOfMonth()))->toBe(300000)
        ->and(FinanceReport::prorata(300000, $from, $from->addDays(9)->endOfDay()))->toBe(100000)
        ->and(FinanceReport::prorata(310000, CarbonImmutable::parse('2026-12-31'), CarbonImmutable::parse('2027-01-01')->endOfDay()))->toBe(20000);
});

it('taxas por forma, custos fixos, despesas e resultado do período', function () {
    fin($this, 'put', 'finance/fees', ['fees' => [
        ['method' => 'credit', 'percent' => '3,99', 'fixed' => '0'],
        ['method' => 'pix', 'percent' => '0,99', 'fixed' => '0,10'],
    ]])->assertOk()->assertJsonPath('data.0.method', 'credit')->assertJsonPath('data.0.percent', '3.99');
    fin($this, 'put', 'finance/fees', ['fees' => [['method' => 'credit', 'percent' => 'abc']]])->assertStatus(422);

    $rent = fin($this, 'post', 'finance/fixed-costs', ['name' => 'Aluguel', 'category' => 'bills', 'amount' => '3.000,00'])->assertStatus(422);
    $rent = fin($this, 'post', 'finance/fixed-costs', ['name' => 'Aluguel', 'category' => 'bills', 'amount' => '3000,00', 'due_day' => 10])->assertCreated()->json('data.id');

    fin($this, 'post', 'session', ['branch' => $this->branch, 'opening_amount' => '100'])->assertCreated();
    finSale($this, 'credit', '100,00');   // taxa 3,99
    finSale($this, 'pix', '50,00');       // taxa 0,50 + 0,10 = 0,60
    finSale($this, 'cash', '20,00');      // sem taxa
    fin($this, 'post', 'entries', ['branch' => $this->branch, 'category' => 'supplier', 'method' => 'pix', 'amount' => '30,00', 'description' => 'Farinha'])->assertCreated();
    fin($this, 'post', 'entries', ['branch' => $this->branch, 'category' => 'bills', 'method' => 'pix', 'amount' => '3000,00', 'fixed_cost' => $rent])->assertCreated()
        ->assertJsonPath('data.description', 'Aluguel')->assertJsonPath('data.fixed_cost', true);
    fin($this, 'post', 'entries', ['branch' => $this->branch, 'category' => 'withdrawal', 'method' => 'cash', 'amount' => '50,00'])->assertCreated();
    fin($this, 'post', 'entries', ['branch' => $this->branch, 'category' => 'supply', 'method' => 'cash', 'amount' => '10,00', 'fixed_cost' => $rent])->assertStatus(422);

    $today = now($this->a->timezone)->toDateString();
    $sum = fin($this, 'get', "finance/summary?from={$today}&to={$today}")->assertOk()->json('data');
    $rentDay = FinanceReport::prorata(300000, CarbonImmutable::parse($today, $this->a->timezone), CarbonImmutable::parse($today, $this->a->timezone)->endOfDay());
    $result = 17000 - 459 - 3000 - $rentDay;
    expect($sum)->toMatchArray([
        'sales' => '170.00', 'fees' => '4.59', 'net_sales' => '165.41', 'expenses' => '30.00',
        'fixed_paid_in_cash' => '3000.00', 'fixed_costs' => number_format($rentDay / 100, 2, '.', ''),
        'result' => number_format($result / 100, 2, '.', ''),
        'cash_moves' => ['supply' => '0.00', 'withdrawal' => '50.00'],
    ])->and(collect($sum['by_method'])->firstWhere('method', 'credit'))->toMatchArray(['gross' => '100.00', 'fees' => '3.99', 'net' => '96.01']);

    // Fechamento impresso: operador, taxas e categorias.
    $sessionId = fin($this, 'get', "session?branch={$this->branch}")->json('data.id');
    fin($this, 'get', "sessions/{$sessionId}/report")->assertOk()
        ->assertJsonPath('data.sales', '170.00')->assertJsonPath('data.sales_count', 3)->assertJsonPath('data.fees', '4.59')
        ->assertJsonPath('data.by_operator.0.count', 6);

    // Custo pago pelo caixa não é apagado: só desativado.
    fin($this, 'delete', "finance/fixed-costs/{$rent}")->assertOk()->assertJsonPath('data.active', false);
    $other = fin($this, 'post', 'finance/fixed-costs', ['name' => 'Internet', 'category' => 'bills', 'amount' => '120'])->json('data.id');
    fin($this, 'delete', "finance/fixed-costs/{$other}")->assertNoContent();
});

it('isolamento e permissão: atendente não vê o financeiro; outro restaurante não vê custos', function () {
    fin($this, 'post', 'finance/fixed-costs', ['name' => 'Aluguel', 'category' => 'bills', 'amount' => '1000'])->assertCreated();
    $attendant = merchantLogin($this->a, makeMember($this->a, role: Role::ATTENDANT, branch: $this->fa->branch));
    fin($this, 'get', 'finance/fixed-costs', session: $attendant)->assertForbidden();
    fin($this, 'get', 'finance/summary?from=2026-11-01&to=2026-11-30', session: $attendant)->assertForbidden();

    $ownerB = merchantLogin($this->b, makeMember($this->b));
    withSession($ownerB)->getJson(tenantUrl($this->b, 'merchant/cash/finance/fixed-costs'))->assertOk()->assertJsonCount(0, 'data');
    expect(inTenant($this->b, fn () => CashEntry::query()->count()))->toBe(0);
});
