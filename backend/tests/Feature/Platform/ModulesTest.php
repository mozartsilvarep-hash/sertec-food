<?php

use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\Plan;
use App\Modules\Platform\Models\Subscription;
use Database\Seeders\PlanSeeder;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\CatalogFixture;
use Tests\Support\Shop;

beforeEach(function () {
    $this->seed(PlanSeeder::class);
    $this->admin = User::factory()->platformAdmin()->create();
    $this->adminSession = adminLogin($this->admin);
    $this->a = makeTenant('tenant-a');
    $this->fa = CatalogFixture::create($this->a);
    $this->owner = makeMember($this->a);
    $this->session = merchantLogin($this->a, $this->owner);
    $this->branch = $this->fa->branch->public_id;
});

function modAdmin(object $t, string $method, string $path, array $body = []): TestResponse
{
    return withSession($t->adminSession)->json($method, adminUrl('admin/'.$path), $body);
}

/** Assinatura do plano "cardapio" com estes módulos. */
function modSubscribe(object $t, array $modules): void
{
    $plan = Plan::query()->where('slug', 'cardapio')->firstOrFail();
    inTenant($t->a, fn () => Subscription::query()->create(['plan_id' => $plan->id, 'modules_json' => $modules, 'status' => Subscription::STATUS_ACTIVE]));
}

function modPrice(object $t): void
{
    modAdmin($t, 'PATCH', 'billing/modules/menu', ['prices' => ['1' => '49,90', '12' => '499,00']])->assertOk();
    modAdmin($t, 'PATCH', 'billing/modules/pos', ['prices' => ['1' => '79,90', '12' => '799,00']])->assertOk();
    modAdmin($t, 'PATCH', 'billing/modules/tabs', ['prices' => ['1' => '39,90']])->assertOk();
}

it('só o Cardápio digital: loja vende, PDV, mesas e estoque ficam fechados', function () {
    modSubscribe($this, ['menu']);
    $token = Shop::newCart($this->a);
    Shop::addSoda($this->fa, $token)->assertCreated();
    Shop::checkout($this->a, Shop::pickupPayload($token, ['payment' => ['method' => 'cash']]))->assertCreated();

    foreach (['merchant/cash/session', 'merchant/cash/tabs', 'merchant/stock'] as $path) {
        withSession($this->session)->getJson(tenantUrl($this->a, $path."?branch={$this->branch}"))->assertForbidden()->assertJsonPath('code', 'feature_unavailable');
    }
    withSession($this->session)->getJson(tenantUrl($this->a, 'merchant/me'))->assertOk()
        ->assertJsonPath('data.modules', ['menu'])->assertJsonPath('data.features', fn ($f) => in_array('online_ordering', $f, true) && ! in_array('cashbook', $f, true));
});

it('só o PDV: cardápio online fechado, venda no balcão funciona com o produto pelo painel', function () {
    modSubscribe($this, ['pos']);
    $this->getJson(tenantUrl($this->a, 'storefront'))->assertForbidden()->assertJsonPath('code', 'feature_unavailable');
    $this->postJson(tenantUrl($this->a, 'carts'), ['branch_id' => $this->branch])->assertForbidden();
    $this->postJson(tenantUrl($this->a, 'checkout'), [])->assertForbidden();

    withSession($this->session)->postJson(tenantUrl($this->a, 'merchant/cash/session'), ['branch' => $this->branch, 'opening_amount' => '0'])->assertCreated();
    withSession($this->session)->getJson(tenantUrl($this->a, 'merchant/cash/pos/products/'.$this->fa->pizza->public_id."?branch={$this->branch}"))
        ->assertOk()->assertJsonPath('data.id', $this->fa->pizza->public_id);
    withSession($this->session)->withHeader('Idempotency-Key', (string) Str::uuid())->postJson(tenantUrl($this->a, 'merchant/cash/pos/sales'), [
        'branch' => $this->branch,
        'items' => [['product_id' => $this->fa->soda->public_id, 'variant_id' => $this->fa->sodaVariant->public_id, 'quantity' => 1]],
        'payments' => [['method' => 'cash', 'amount' => '6,50']],
    ])->assertCreated();
    // Mesas é outro módulo.
    withSession($this->session)->getJson(tenantUrl($this->a, 'merchant/cash/tabs'))->assertForbidden();
});

it('mesas e comandas exige o PDV', function () {
    modPrice($this);
    modAdmin($this, 'PUT', "tenants/{$this->a->public_id}/billing", ['plan' => 'cardapio', 'billing_months' => 1, 'modules' => ['menu', 'tabs']])
        ->assertStatus(422)->assertJsonPath('message', 'Mesas e comandas precisa do módulo PDV e caixa.');
    modAdmin($this, 'PUT', "tenants/{$this->a->public_id}/billing", ['plan' => 'cardapio', 'billing_months' => 1, 'modules' => []])->assertStatus(422);

    // Gravado sem o PDV (dado antigo): mesas não liberam.
    modSubscribe($this, ['tabs']);
    withSession($this->session)->getJson(tenantUrl($this->a, 'merchant/cash/tabs'))->assertForbidden();
});

it('preço = soma dos módulos no ciclo do cliente; fatura mostra cada módulo', function () {
    modPrice($this);
    $res = modAdmin($this, 'PUT', "tenants/{$this->a->public_id}/billing", ['plan' => 'cardapio', 'billing_months' => 1, 'modules' => ['pos', 'menu']])->assertOk()
        ->assertJsonPath('data.subscription.modules', ['menu', 'pos'])->assertJsonPath('data.subscription.price', '129.80');
    expect(array_column($res->json('data.subscription.lines'), 'amount', 'label'))->toBe(['Cardápio digital' => '49.90', 'PDV e caixa' => '79.90']);

    // Anual: soma dos anuais.
    modAdmin($this, 'PUT', "tenants/{$this->a->public_id}/billing", ['plan' => 'cardapio', 'billing_months' => 12, 'modules' => ['menu', 'pos']])->assertOk()
        ->assertJsonPath('data.subscription.price', '1298.00');
    // Mesas não tem preço anual: entra sem custo (a tela avisa), soma não muda.
    $withTabs = modAdmin($this, 'PUT', "tenants/{$this->a->public_id}/billing", ['plan' => 'cardapio', 'billing_months' => 12, 'modules' => ['menu', 'pos', 'tabs']])->assertOk();
    expect($withTabs->json('data.subscription.price'))->toBe('1298.00')
        ->and(collect($withTabs->json('data.subscription.lines'))->firstWhere('module', 'tabs')['amount'])->toBeNull();

    modAdmin($this, 'PUT', "tenants/{$this->a->public_id}/billing", ['plan' => 'cardapio', 'billing_months' => 1, 'modules' => ['menu', 'pos', 'tabs']])->assertOk();
    $invoice = modAdmin($this, 'POST', "tenants/{$this->a->public_id}/billing/invoices")->assertCreated()->json('data.invoices.0');
    expect($invoice['amount'])->toBe('169.70')->and($invoice['plan_name'])->toBe('Cardápio digital + PDV e caixa + Mesas e comandas')
        ->and(array_column($invoice['lines'], 'amount'))->toBe(['49.90', '79.90', '39.90']);

    // Tirar um módulo atualiza a fatura em aberto (ainda sem Pix).
    $after = modAdmin($this, 'PUT', "tenants/{$this->a->public_id}/billing", ['plan' => 'cardapio', 'billing_months' => 1, 'modules' => ['menu']])->assertOk();
    expect($after->json('data.invoices.0.amount'))->toBe('49.90');
    // Valor próprio (desconto do cliente) vale sobre a soma.
    modAdmin($this, 'PUT', "tenants/{$this->a->public_id}/billing", ['plan' => 'cardapio', 'billing_months' => 1, 'modules' => ['menu', 'pos'], 'price' => '100,00'])->assertOk()
        ->assertJsonPath('data.subscription.price', '100.00')->assertJsonPath('data.subscription.catalog_price', '129.80');

    // O dono vê os módulos na assinatura e no painel.
    withSession($this->session)->getJson(tenantUrl($this->a, 'merchant/billing'))->assertOk()->assertJsonPath('data.subscription.modules', ['menu', 'pos']);
});

it('sem nenhum preço no período, pede valor próprio; só superadmin muda preço do módulo', function () {
    modAdmin($this, 'PUT', "tenants/{$this->a->public_id}/billing", ['plan' => 'cardapio', 'billing_months' => 12, 'modules' => ['menu']])
        ->assertStatus(422)->assertJsonPath('code', 'plan_period_unavailable');
    modAdmin($this, 'PUT', "tenants/{$this->a->public_id}/billing", ['plan' => 'cardapio', 'billing_months' => 12, 'modules' => ['menu'], 'price' => '0'])->assertOk();

    $list = modAdmin($this, 'GET', 'billing/modules')->assertOk();
    expect(array_column($list->json('data'), 'key'))->toBe(['menu', 'pos', 'tabs', 'stock'])
        ->and(collect($list->json('data'))->firstWhere('key', 'tabs')['requires'])->toBe('pos')
        ->and(collect($list->json('data'))->firstWhere('key', 'menu')['tenants'])->toBe(1);
    // Plano não liga mais os módulos.
    expect(array_column(modAdmin($this, 'GET', 'billing/plans')->json('meta.features'), 'key'))->not->toContain('cashbook');

    $support = User::factory()->platformAdmin()->create(['platform_role' => 'support']);
    withSession(adminLogin($support))->patchJson(adminUrl('admin/billing/modules/menu'), ['prices' => ['1' => '1,00']])->assertForbidden();
});

it('novo estabelecimento nasce com os módulos escolhidos (padrão: Cardápio)', function () {
    $made = modAdmin($this, 'POST', 'tenants', ['name' => 'Bar do Zé', 'slug' => 'bar-do-ze', 'owner_name' => 'Zé', 'owner_email' => 'ze@bar.test', 'plan' => 'cardapio', 'modules' => ['pos', 'tabs']])
        ->assertCreated();
    expect($made->json('data.modules'))->toBe(['pos', 'tabs']);
    modAdmin($this, 'POST', 'tenants', ['name' => 'Doce', 'slug' => 'doce-lar', 'owner_name' => 'Ana', 'owner_email' => 'ana@doce.test', 'plan' => 'cardapio'])
        ->assertCreated()->assertJsonPath('data.modules', ['menu']);
    modAdmin($this, 'POST', 'tenants', ['name' => 'Erro', 'slug' => 'erro-x', 'owner_name' => 'X', 'owner_email' => 'x@x.test', 'plan' => 'cardapio', 'modules' => ['tabs']])
        ->assertStatus(422);
});
