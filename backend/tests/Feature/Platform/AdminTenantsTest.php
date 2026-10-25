<?php

use App\Modules\Identity\Enums\PlatformRole;
use App\Modules\Identity\Models\User;
use App\Modules\Platform\Domains\DnsResolver;
use App\Modules\Platform\Models\AuditLog;
use App\Modules\Platform\Models\Plan;
use App\Modules\Tenancy\Models\Tenant;
use Database\Seeders\PlanSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    $this->seed(PlanSeeder::class);
    $this->admin = User::factory()->platformAdmin()->create();
    $this->session = adminLogin($this->admin);
    $this->dns = new class implements DnsResolver
    {
        /** @var array<string, list<string>> */
        public array $records = [];

        public function txt(string $name): array
        {
            return $this->records[$name] ?? [];
        }
    };
    app()->instance(DnsResolver::class, $this->dns);
});

function admin(object $t, string $method, string $path, array $body = [])
{
    return withSession($t->session)->{$method.'Json'}(adminUrl('admin/'.$path), $body);
}

it('cria estabelecimento ativo com subdomínio, matriz, dono (senha uma vez) e plano', function () {
    $plan = Plan::query()->value('slug');

    $res = admin($this, 'post', 'tenants', [
        'name' => 'Cantina da Rosa', 'slug' => 'cantina-rosa', 'owner_name' => 'Rosa Lima',
        'owner_email' => 'rosa@cantina.test', 'plan' => $plan, 'activate' => true,
    ])->assertCreated()
        ->assertJsonPath('data.slug', 'cantina-rosa')
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.host', 'cantina-rosa.'.config('tenancy.base_domain'))
        ->assertJsonPath('data.plan', $plan)
        ->assertJsonPath('data.owner.email', 'rosa@cantina.test')
        ->assertJsonPath('data.branches.0.name', 'Matriz');

    $password = $res->json('data.owner_password');
    expect($password)->toBeString()->toHaveLength(16)
        ->and(Hash::check($password, User::query()->where('email', 'rosa@cantina.test')->value('password')))->toBeTrue();

    admin($this, 'post', 'tenants', ['name' => 'Outro', 'slug' => 'cantina-rosa', 'owner_name' => 'Outra Pessoa', 'owner_email' => 'y@y.test'])
        ->assertStatus(409)->assertJsonPath('code', 'slug_taken');
    admin($this, 'post', 'tenants', ['name' => 'Outro', 'slug' => 'admin', 'owner_name' => 'Outra Pessoa', 'owner_email' => 'y@y.test'])
        ->assertStatus(422)->assertJsonPath('code', 'slug_invalid');

    // O dono entra no próprio painel com essa senha.
    $tenant = Tenant::query()->where('slug', 'cantina-rosa')->sole();
    merchantLogin($tenant, User::query()->where('email', 'rosa@cantina.test')->sole(), $password);
});

it('lista, busca, suspende e reativa; suspenso deixa de abrir o cardápio', function () {
    $t = makeTenant('pizzaria-sol');
    makeTenant('burger-lua');

    admin($this, 'get', 'tenants?q=sol')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.slug', 'pizzaria-sol');

    admin($this, 'patch', "tenants/{$t->public_id}", ['status' => 'suspended'])->assertOk()->assertJsonPath('data.status', 'suspended');
    $this->getJson(tenantUrl($t, 'storefront'))->assertForbidden()->assertJsonPath('code', 'tenant_unavailable');

    admin($this, 'patch', "tenants/{$t->public_id}", ['status' => 'active'])->assertOk();
    $this->getJson(tenantUrl($t, 'storefront'))->assertOk();

    expect(AuditLog::query()->where('action', 'tenant.updated')->count())->toBe(2);
});

it('redefine a senha do dono, mostra uma vez e encerra as sessões dele', function () {
    $t = makeTenant('pizzaria-sol');
    $owner = makeMember($t);
    // Sessão antiga do dono
    DB::table('sessions')->insert(['id' => 'sessao-antiga-do-dono-0000000000000000', 'user_id' => $owner->id, 'payload' => '', 'last_activity' => time()]);

    $res = admin($this, 'post', "tenants/{$t->public_id}/owner-password")->assertOk()->assertJsonPath('data.email', $owner->email);

    expect(Hash::check($res->json('data.password'), $owner->fresh()->password))->toBeTrue()
        ->and(DB::table('sessions')->where('user_id', $owner->id)->count())->toBe(0);
});

it('domínio próprio: TXT de verificação, só verificado vira principal e libera o TLS', function () {
    $t = makeTenant('pizzaria-sol');
    makeMember($t);

    $add = admin($this, 'post', "tenants/{$t->public_id}/domains", ['hostname' => 'Pedidos.PizzariaSol.com.br'])->assertCreated()
        ->assertJsonPath('data.hostname', 'pedidos.pizzariasol.com.br')
        ->assertJsonPath('data.txt_name', '_sertec-verify.pedidos.pizzariasol.com.br');
    $id = $add->json('data.id');

    // Antes de verificar: não resolve o tenant, não ganha certificado, não vira principal.
    $this->getJson('http://pedidos.pizzariasol.com.br/api/v1/storefront')->assertNotFound();
    $this->get('http://localhost/api/v1/internal/tls-ask?domain=pedidos.pizzariasol.com.br')->assertNotFound();
    admin($this, 'post', "tenants/{$t->public_id}/domains/{$id}/primary")->assertStatus(409);
    admin($this, 'post', "tenants/{$t->public_id}/domains/{$id}/verify")->assertStatus(422)->assertJsonPath('code', 'domain_not_verified');

    $this->dns->records['_sertec-verify.pedidos.pizzariasol.com.br'] = ['outra-coisa', $add->json('data.txt_value')];
    admin($this, 'post', "tenants/{$t->public_id}/domains/{$id}/verify")->assertOk()->assertJsonPath('data.verified', true);

    $this->getJson('http://pedidos.pizzariasol.com.br/api/v1/storefront')->assertOk()->assertJsonPath('data.slug', 'pizzaria-sol');
    $this->get('http://localhost/api/v1/internal/tls-ask?domain=PEDIDOS.pizzariasol.com.br')->assertOk();
    $this->get('http://localhost/api/v1/internal/tls-ask?domain=qualquer.coisa.com')->assertNotFound();
    $this->get('http://localhost/api/v1/internal/tls-ask?domain='.config('tenancy.admin_host'))->assertOk();

    admin($this, 'post', "tenants/{$t->public_id}/domains/{$id}/primary")->assertOk();
    admin($this, 'get', "tenants/{$t->public_id}")->assertJsonPath('data.host', 'pedidos.pizzariasol.com.br');
    admin($this, 'delete', "tenants/{$t->public_id}/domains/{$id}")->assertStatus(409)->assertJsonPath('code', 'domain_primary');
});

it('domínio da plataforma, já usado ou inválido é recusado', function () {
    $a = makeTenant('pizzaria-sol');
    $b = makeTenant('burger-lua');
    admin($this, 'post', "tenants/{$a->public_id}/domains", ['hostname' => 'x.'.config('tenancy.base_domain')])->assertStatus(422)->assertJsonPath('code', 'domain_reserved');
    admin($this, 'post', "tenants/{$a->public_id}/domains", ['hostname' => 'pedidos.sol.com'])->assertCreated();
    admin($this, 'post', "tenants/{$b->public_id}/domains", ['hostname' => 'pedidos.sol.com'])->assertStatus(409);
    admin($this, 'post', "tenants/{$a->public_id}/domains", ['hostname' => 'http://x'])->assertStatus(422);
});

it('suporte só consulta; superadmin altera', function () {
    $t = makeTenant('pizzaria-sol');
    $support = User::factory()->platformAdmin(PlatformRole::Support)->create();
    $session = adminLogin($support);

    withSession($session)->getJson(adminUrl('admin/tenants'))->assertOk();
    withSession($session)->getJson(adminUrl("admin/tenants/{$t->public_id}"))->assertOk();
    withSession($session)->patchJson(adminUrl("admin/tenants/{$t->public_id}"), ['status' => 'suspended'])->assertForbidden();
    withSession($session)->postJson(adminUrl("admin/tenants/{$t->public_id}/owner-password"))->assertForbidden();
    withSession($session)->postJson(adminUrl('admin/tenants'), ['name' => 'x'])->assertForbidden();
});

it('rotas do painel SerTec não existem em host de restaurante', function () {
    $t = makeTenant('pizzaria-sol');
    $this->getJson(tenantUrl($t, 'admin/tenants'))->assertNotFound();
});

it('auditoria da plataforma lista ações de todos os tenants com filtro', function () {
    $t = makeTenant('pizzaria-sol');
    admin($this, 'patch', "tenants/{$t->public_id}", ['status' => 'suspended'])->assertOk();

    admin($this, 'get', "audit?tenant={$t->public_id}")->assertOk()
        ->assertJsonPath('data.0.action', 'tenant.updated')
        ->assertJsonPath('data.0.tenant.slug', 'pizzaria-sol')
        ->assertJsonPath('data.0.actor.email', $this->admin->email);
});
