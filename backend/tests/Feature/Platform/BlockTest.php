<?php

use App\Modules\Identity\Models\User;
use App\Modules\Platform\Billing\Mail\InvoiceNoticeMail;
use App\Modules\Platform\Domains\DnsResolver;
use App\Modules\Platform\Models\Invoice;
use App\Modules\Platform\Models\Subscription;
use App\Modules\Platform\Ops\HealthCheck;
use App\Modules\Platform\Ops\MailDnsCheck;
use Carbon\CarbonImmutable;
use Database\Seeders\PlanSeeder;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\Support\CatalogFixture;
use Tests\Support\Shop;

beforeEach(function () {
    Mail::fake();
    $this->seed(PlanSeeder::class);
    $this->admin = User::factory()->platformAdmin()->create();
    $this->a = makeTenant('tenant-a');
    $this->fa = CatalogFixture::create($this->a);
    $this->owner = makeMember($this->a);
});

// O relógio anda (travelTo): cada chamada entra de novo, a sessão não expira.
function blkAdmin(object $t, string $method, string $path, array $body = []): TestResponse
{
    return withSession(adminLogin($t->admin))->json($method, adminUrl('admin/'.$path), $body);
}

function blkPanel(object $t, string $path): TestResponse
{
    return withSession(merchantLogin($t->a, $t->owner))->getJson(tenantUrl($t->a, $path));
}

/** Cliente com Cardápio + PDV, R$ 99,90/mês, pago até 30/11 (fatura vence 01/12). */
function blkSetup(object $t, bool $autoBlock = true): Invoice
{
    blkAdmin($t, 'PATCH', 'billing/modules/menu', ['prices' => ['1' => '99,90']])->assertOk();
    blkAdmin($t, 'PUT', "tenants/{$t->a->public_id}/billing", [
        'plan' => 'cardapio', 'billing_months' => 1, 'paid_until' => '2026-11-30', 'modules' => ['menu', 'pos'], 'auto_block' => $autoBlock,
    ])->assertOk();
    $t->travelTo(CarbonImmutable::parse('2026-11-24 09:00'));
    $t->artisan('billing:run')->assertSuccessful();

    return inTenant($t->a, fn () => Invoice::query()->sole());
}

function blkRun(object $t, string $day): void
{
    $t->travelTo(CarbonImmutable::parse($day.' 09:00'));
    $t->artisan('billing:run')->assertSuccessful();
}

function blkMe(object $t): TestResponse
{
    return blkPanel($t, 'merchant/me');
}

it('avisa 2 dias antes, bloqueia no 8º dia de atraso e libera na hora ao pagar', function () {
    $inv = blkSetup($this);
    expect($inv->due_date->toDateString())->toBe('2026-12-01');

    blkRun($this, '2026-12-05');
    Mail::assertNotSent(InvoiceNoticeMail::class, fn ($m) => $m->kind === 'block_soon');
    blkRun($this, '2026-12-06');
    Mail::assertSent(InvoiceNoticeMail::class, fn ($m) => $m->kind === 'block_soon' && $m->blockDate === '09/12/2026');
    blkPanel($this, 'merchant/billing')->assertJsonPath('data.subscription.block_date', '2026-12-09');

    // 7º dia de atraso: ainda funciona.
    blkRun($this, '2026-12-08');
    blkMe($this)->assertJsonPath('data.blocked', false);
    blkPanel($this, 'merchant/cash/session')->assertOk();

    // 8º dia: bloqueado.
    blkRun($this, '2026-12-09');
    Mail::assertSent(InvoiceNoticeMail::class, fn ($m) => $m->kind === 'blocked');
    blkMe($this)->assertOk()->assertJsonPath('data.blocked', true)->assertJsonPath('data.features', []);
    blkPanel($this, 'merchant/cash/session')->assertForbidden()->assertJsonPath('code', 'account_blocked');
    blkPanel($this, 'merchant/orders')->assertForbidden();
    $this->getJson(tenantUrl($this->a, 'storefront'))->assertForbidden();
    // A tela de pagar continua.
    blkPanel($this, 'merchant/billing')->assertOk()->assertJsonPath('data.invoices.0.status', 'open');
    blkAdmin($this, 'GET', 'tenants')->assertJsonPath('data.0.blocked', true);
    // Rodar de novo não manda outro e-mail.
    blkRun($this, '2026-12-10');
    Mail::assertSent(InvoiceNoticeMail::class, fn ($m) => $m->kind === 'blocked', 1);

    blkAdmin($this, 'POST', "billing/invoices/{$inv->public_id}/pay", ['note' => 'Pix por fora'])->assertOk();
    blkMe($this)->assertJsonPath('data.blocked', false)->assertJsonPath('data.modules', ['menu', 'pos']);
    blkPanel($this, 'merchant/cash/session')->assertOk();
    $token = Shop::newCart($this->a);
    Shop::addSoda($this->fa, $token)->assertCreated();
});

it('cliente fora do bloqueio automático não é bloqueado; ligar de novo bloqueia', function () {
    blkSetup($this, autoBlock: false);
    blkRun($this, '2026-12-15');
    blkMe($this)->assertJsonPath('data.blocked', false);
    Mail::assertNotSent(InvoiceNoticeMail::class, fn ($m) => in_array($m->kind, ['block_soon', 'blocked'], true));

    blkAdmin($this, 'PUT', "tenants/{$this->a->public_id}/billing", ['plan' => 'cardapio', 'billing_months' => 1, 'auto_block' => true])->assertOk()
        ->assertJsonPath('data.subscription.blocked_at', fn ($v) => $v !== null);
    blkMe($this)->assertJsonPath('data.blocked', true);
});

it('cancelar a fatura atrasada desbloqueia', function () {
    $inv = blkSetup($this);
    blkRun($this, '2026-12-10');
    blkMe($this)->assertJsonPath('data.blocked', true);
    blkAdmin($this, 'POST', "billing/invoices/{$inv->public_id}/cancel", ['note' => 'Acordo'])->assertOk();
    blkMe($this)->assertJsonPath('data.blocked', false);
    expect(inTenant($this->a, fn () => Subscription::query()->latest('id')->value('blocked_at')))->toBeNull();
});

it('status público para o monitor: só ok, sem detalhes', function () {
    HealthCheck::beat();
    $res = $this->getJson('/api/v1/status');
    expect($res->json())->toBe(['ok' => $res->status() === 200]);
});

it('confere SPF, DKIM e DMARC e diz o que falta', function () {
    $dns = new class implements DnsResolver
    {
        /** @var array<string, list<string>> */
        public array $records = [];

        public function txt(string $name): array
        {
            return $this->records[$name] ?? [];
        }
    };
    $dns->records['sertec.test'] = ['zoho-verification=x', 'v=spf1 include:zohomail.com ~all'];
    $check = new MailDnsCheck($dns);

    $r = $check->run('sertec.test');
    expect($r['checks']['spf']['ok'])->toBeTrue()->and($r['checks']['dkim']['ok'])->toBeFalse()
        ->and($r['checks']['dmarc']['fix'])->toContain('_dmarc.sertec.test');

    $dns->records['zmail._domainkey.sertec.test'] = ['v=DKIM1; k=rsa; p=MIGf'];
    $dns->records['_dmarc.sertec.test'] = ['v=DMARC1; p=none'];
    $dns->records['sertec.test'][] = 'v=spf1 a ~all';
    $r = $check->run('sertec.test');
    expect($r['checks']['dkim']['detail'])->toBe('seletor zmail')->and($r['checks']['dmarc']['ok'])->toBeTrue()
        ->and($r['checks']['spf']['ok'])->toBeFalse()->and($r['checks']['spf']['detail'])->toContain('2 registros');
});
