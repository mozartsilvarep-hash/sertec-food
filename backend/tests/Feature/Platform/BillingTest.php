<?php

use App\Modules\Identity\Enums\PlatformRole;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Platform\Billing\Mail\InvoiceNoticeMail;
use App\Modules\Platform\Features\FeatureGate;
use App\Modules\Platform\Models\Invoice;
use App\Modules\Platform\Models\Plan;
use App\Modules\Platform\Models\Subscription;
use Carbon\CarbonImmutable;
use Database\Seeders\PlanSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakeMercadoPago;

const SERTEC_TOKEN = 'TEST-sertec-conta-de-cobranca-1234567890';
const SERTEC_SECRET = 'segredo-cobranca-sertec-123456';

beforeEach(function () {
    Mail::fake();
    $this->seed(PlanSeeder::class);
    $this->mp = FakeMercadoPago::install();
    $this->mp->account(SERTEC_TOKEN, '777000');
    $this->admin = User::factory()->platformAdmin()->create();
    $this->adminSession = adminLogin($this->admin);
    $this->a = makeTenant('tenant-a');
    $this->b = makeTenant('tenant-b');
    $this->owner = makeMember($this->a);
    $this->session = merchantLogin($this->a, $this->owner);
});

function adm(object $t, string $method, string $path, array $body = []): TestResponse
{
    return withSession($t->adminSession)->{$method.'Json'}(adminUrl('admin/'.$path), $body);
}

function connectSertec(object $t): void
{
    adm($t, 'put', 'billing/settings', ['access_token' => SERTEC_TOKEN, 'webhook_secret' => SERTEC_SECRET])->assertOk();
}

function priceCardapio(object $t): void
{
    adm($t, 'patch', 'billing/plans/cardapio', ['prices' => ['1' => '99,90', '3' => '269,70', '6' => null, '12' => '999,00']])->assertOk();
}

function billingWebhook(string $paymentId, ?string $secret = SERTEC_SECRET): TestResponse
{
    $req = 'req-'.bin2hex(random_bytes(4));
    $ts = (string) time();
    $headers = ['x-request-id' => $req];
    if ($secret !== null) {
        $headers['x-signature'] = "ts={$ts},v1=".hash_hmac('sha256', "id:{$paymentId};request-id:{$req};ts:{$ts};", $secret);
    }

    return test()->withHeaders($headers)->postJson(adminUrl('billing/webhooks/mercadopago?data.id='.$paymentId.'&type=payment'), [
        'id' => (string) random_int(10_000_000, 99_999_999), 'type' => 'payment', 'action' => 'payment.updated', 'data' => ['id' => $paymentId],
    ]);
}

function invoiceOf($tenant): Invoice
{
    return inTenant($tenant, fn () => Invoice::query()->latest('id')->firstOrFail());
}

it('planos: preço por período e recursos no painel; seed não sobrescreve', function () {
    priceCardapio($this);
    adm($this, 'patch', 'billing/plans/cardapio', ['prices' => ['1' => 'abc']])->assertStatus(422);
    adm($this, 'patch', 'billing/plans/cardapio', ['features' => ['reports' => true], 'description' => 'Para quem está começando'])->assertOk()
        ->assertJsonPath('data.features.reports', true)->assertJsonPath('data.features.loyalty', true)
        ->assertJsonPath('data.prices.3', '269.70')->assertJsonPath('data.prices.6', null);

    $this->seed(PlanSeeder::class);
    expect(Plan::query()->where('slug', 'cardapio')->first()->priceFor(1))->toBe('99.90');

    adm($this, 'post', 'billing/plans', ['slug' => 'premium', 'name' => 'Premium', 'prices' => ['12' => '1990']])->assertCreated()
        ->assertJsonPath('data.prices.12', '1990.00')->assertJsonPath('data.features.loyalty', false);

    // Suporte só consulta.
    $support = User::factory()->platformAdmin(PlatformRole::Support)->create();
    withSession(adminLogin($support))->patchJson(adminUrl('admin/billing/plans/cardapio'), ['name' => 'X'])->assertForbidden();
});

it('conta MP da SerTec: token validado, nunca devolvido e guardado criptografado', function () {
    adm($this, 'put', 'billing/settings', ['access_token' => 'TEST-token-que-nao-existe-1234567890'])->assertStatus(422);
    $res = adm($this, 'put', 'billing/settings', ['access_token' => SERTEC_TOKEN, 'webhook_secret' => SERTEC_SECRET])->assertOk()
        ->assertJsonPath('data.connected', true)->assertJsonPath('data.account_id', '777000')->assertJsonPath('data.has_webhook_secret', true);
    expect($res->getContent())->not->toContain(SERTEC_TOKEN)->not->toContain(SERTEC_SECRET)
        ->and(DB::table('platform_settings')->value('value'))->not->toContain('sertec-conta');
});

it('mensalidade: 1ª fatura, Pix na conta da SerTec, webhook dá baixa e avisa', function () {
    connectSertec($this);
    priceCardapio($this);
    adm($this, 'put', "tenants/{$this->a->public_id}/billing", ['plan' => 'cardapio', 'billing_months' => 6])
        ->assertStatus(422)->assertJsonPath('code', 'plan_period_unavailable');
    adm($this, 'put', "tenants/{$this->a->public_id}/billing", ['plan' => 'cardapio', 'billing_months' => 3])->assertOk()
        ->assertJsonPath('data.subscription.price', '269.70')->assertJsonPath('data.subscription.period_label', 'Trimestral');

    $page = withSession($this->session)->getJson(tenantUrl($this->a, 'merchant/billing'))->assertOk()
        ->assertJsonPath('data.pix_available', true)->assertJsonCount(1, 'data.invoices')
        ->assertJsonPath('data.invoices.0.amount', '269.70')
        ->assertJsonPath('data.invoices.0.due_date', now()->addDays(5)->toDateString())
        ->assertJsonPath('data.invoices.0.period_end', now()->addMonthsNoOverflow(3)->subDay()->toDateString());
    $id = $page->json('data.invoices.0.id');

    $pix = withSession($this->session)->postJson(tenantUrl($this->a, "merchant/billing/invoices/{$id}/pix"))->assertOk()->json('data.pix');
    expect($pix['code'])->toStartWith('000201');
    $req = $this->mp->requestsTo('/v1/payments')[0];
    expect($req['token'])->toBe(SERTEC_TOKEN)
        ->and($req['body']['external_reference'])->toBe("sertec-fatura:{$this->a->public_id}:{$id}")
        ->and($req['body']['transaction_amount'])->toBe(269.7)
        ->and($req['body']['payer']['email'])->toBe($this->owner->email);
    // Mesmo Pix enquanto vale.
    withSession($this->session)->postJson(tenantUrl($this->a, "merchant/billing/invoices/{$id}/pix"))->assertOk()->assertJsonPath('data.pix.code', $pix['code']);
    expect($this->mp->requestsTo('/v1/payments'))->toHaveCount(1);

    $paymentId = $this->mp->lastPaymentId();
    billingWebhook($paymentId, 'segredo-errado-1234567890')->assertStatus(401);
    billingWebhook($paymentId)->assertOk(); // ainda pendente
    expect(invoiceOf($this->a)->status)->toBe('open');

    $this->mp->set($paymentId, 'approved');
    billingWebhook($paymentId)->assertOk()->assertJsonPath('status', 'received');

    $invoice = invoiceOf($this->a);
    expect($invoice->status)->toBe('paid')->and($invoice->paid_via)->toBe('pix')
        ->and(inTenant($this->a, fn () => Subscription::query()->latest('id')->first()->paid_until->toDateString()))->toBe($invoice->period_end->toDateString());
    Mail::assertSent(InvoiceNoticeMail::class, fn ($m) => $m->kind === 'paid' && $m->hasTo($this->owner->email));
    withSession($this->session)->getJson(tenantUrl($this->a, "merchant/billing/invoices/{$id}"))->assertJsonPath('data.status', 'paid')->assertJsonPath('data.pix', null);
});

it('Pix pago com valor menor ou de outro restaurante não dá baixa', function () {
    connectSertec($this);
    priceCardapio($this);
    adm($this, 'put', "tenants/{$this->a->public_id}/billing", ['plan' => 'cardapio', 'billing_months' => 1])->assertOk();
    $id = withSession($this->session)->getJson(tenantUrl($this->a, 'merchant/billing'))->json('data.invoices.0.id');
    withSession($this->session)->postJson(tenantUrl($this->a, "merchant/billing/invoices/{$id}/pix"))->assertOk();
    $this->mp->set($this->mp->lastPaymentId(), 'approved', ['transaction_amount' => 9.99]);
    billingWebhook($this->mp->lastPaymentId())->assertOk();
    expect(invoiceOf($this->a)->status)->toBe('open');
});

it('isolamento: só o dono vê e paga; outro restaurante não acha a fatura', function () {
    connectSertec($this);
    priceCardapio($this);
    adm($this, 'put', "tenants/{$this->a->public_id}/billing", ['plan' => 'cardapio', 'billing_months' => 1])->assertOk();
    $id = withSession($this->session)->getJson(tenantUrl($this->a, 'merchant/billing'))->json('data.invoices.0.id');

    $manager = makeMember($this->a, null, Role::MANAGER);
    withSession(merchantLogin($this->a, $manager))->getJson(tenantUrl($this->a, 'merchant/billing'))->assertForbidden();

    $ownerB = makeMember($this->b);
    withSession(merchantLogin($this->b, $ownerB))->postJson(tenantUrl($this->b, "merchant/billing/invoices/{$id}/pix"))->assertNotFound();
});

it('rotina: fatura 7 dias antes, aviso 3 dias antes, atraso vira past_due sem cortar o sistema, baixa manual', function () {
    priceCardapio($this);
    adm($this, 'put', "tenants/{$this->a->public_id}/billing", ['plan' => 'cardapio', 'billing_months' => 1, 'paid_until' => '2026-11-30'])->assertOk();
    // Cortesia no B: nunca gera fatura.
    adm($this, 'put', "tenants/{$this->b->public_id}/billing", ['plan' => 'cardapio', 'billing_months' => 1, 'price' => '0'])->assertOk();

    $this->artisan('billing:run', ['--date' => '2026-11-20'])->assertSuccessful();
    expect(inTenant($this->a, fn () => Invoice::query()->count()))->toBe(0);

    $this->artisan('billing:run', ['--date' => '2026-11-24'])->assertSuccessful();
    $inv = invoiceOf($this->a);
    expect([$inv->period_start->toDateString(), $inv->period_end->toDateString(), $inv->due_date->toDateString(), $inv->amount])
        ->toBe(['2026-12-01', '2026-12-31', '2026-12-01', '99.90'])
        ->and(inTenant($this->b, fn () => Invoice::query()->count()))->toBe(0);

    $this->artisan('billing:run', ['--date' => '2026-11-28'])->assertSuccessful();
    $this->artisan('billing:run', ['--date' => '2026-11-29'])->assertSuccessful();
    Mail::assertSent(InvoiceNoticeMail::class, 1);
    Mail::assertSent(InvoiceNoticeMail::class, fn ($m) => $m->kind === 'due_soon');

    $this->artisan('billing:run', ['--date' => '2026-12-02'])->assertSuccessful();
    $this->artisan('billing:run', ['--date' => '2026-12-03'])->assertSuccessful();
    Mail::assertSent(InvoiceNoticeMail::class, fn ($m) => $m->kind === 'overdue');
    Mail::assertSent(InvoiceNoticeMail::class, 2);
    $sub = inTenant($this->a, fn () => Subscription::query()->latest('id')->first());
    expect($sub->status)->toBe('past_due')
        ->and(inTenant($this->a, fn () => app(FeatureGate::class)->enabled('loyalty')))->toBeTrue();
    $this->travelTo(CarbonImmutable::parse('2026-12-03 10:00'));
    adm($this, 'get', 'billing/invoices?status=overdue')->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.tenant.id', $this->a->public_id)->assertJsonPath('meta.overdue_total', '99.90');

    adm($this, 'post', "billing/invoices/{$inv->public_id}/pay", ['note' => 'Transferência'])->assertOk()
        ->assertJsonPath('data.subscription.status', 'active')->assertJsonPath('data.subscription.paid_until', '2026-12-31')
        ->assertJsonPath('data.invoices.0.paid_via', 'manual');
    adm($this, 'post', "billing/invoices/{$inv->public_id}/pay")->assertStatus(409);
    Mail::assertSent(InvoiceNoticeMail::class, fn ($m) => $m->kind === 'paid');
});

it('aviso no painel do dono quando a fatura vence em breve', function () {
    priceCardapio($this);
    adm($this, 'put', "tenants/{$this->a->public_id}/billing", ['plan' => 'cardapio', 'billing_months' => 1, 'paid_until' => CarbonImmutable::today()->addDays(2)->toDateString()])->assertOk();
    adm($this, 'post', "tenants/{$this->a->public_id}/billing/invoices")->assertCreated();

    withSession($this->session)->getJson(tenantUrl($this->a, 'merchant/me'))->assertOk()
        ->assertJsonPath('data.billing.amount', '99.90')->assertJsonPath('data.billing.overdue', false);
});
