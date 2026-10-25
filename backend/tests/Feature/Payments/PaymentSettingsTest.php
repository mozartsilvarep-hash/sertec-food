<?php

use App\Modules\Identity\Models\Role;
use App\Modules\Payments\Models\IntegrationConnection;
use App\Modules\Platform\Models\AuditLog;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeMercadoPago;

beforeEach(function () {
    $this->mp = FakeMercadoPago::install();
    $this->a = makeTenant('tenant-a');
    $this->b = makeTenant('tenant-b');
    $this->owner = makeMember($this->a);
    $this->session = merchantLogin($this->a, $this->owner);
    $this->token = $this->mp->account('TEST-1234567890123456-102512-abcdefabcdef-99887766', '556677');
});

function mpSettings(object $t, string $method = 'get', array $body = [], ?string $session = null)
{
    return withSession($session ?? $t->session)->{$method.'Json'}(tenantUrl($t->a, 'merchant/payments/mercadopago'), $body);
}

it('dono conecta: token validado no provedor, gravado criptografado e nunca devolvido', function () {
    mpSettings($this)->assertOk()->assertJsonPath('data.connected', false);

    $response = mpSettings($this, 'put', ['access_token' => $this->token, 'webhook_secret' => 'abcdef0123456789abcdef'])
        ->assertOk()
        ->assertJsonPath('data.connected', true)
        ->assertJsonPath('data.mode', 'test')
        ->assertJsonPath('data.account_id', '556677')
        ->assertJsonPath('data.token_hint', '7766')
        ->assertJsonPath('data.has_webhook_secret', true)
        ->assertJsonPath('data.webhook_url', 'https://'.hostOf($this->a).'/api/v1/webhooks/mercadopago');

    expect($response->getContent())->not->toContain($this->token)->not->toContain('abcdef0123456789abcdef')
        ->and($this->mp->requestsTo('/users/me')[0]['token'])->toBe($this->token);

    // No banco, só o texto cifrado.
    $raw = DB::selectOne('SELECT credentials FROM integration_connections')->credentials;
    expect($raw)->not->toContain($this->token)->not->toContain('abcdef0123456789');

    // Auditoria sem segredos.
    $audit = inTenant($this->a, fn () => AuditLog::query()->where('action', 'payments.connected')->sole());
    expect(json_encode($audit->metadata_json))->not->toContain($this->token)->not->toContain('abcdef0123456789');

    // Editar sem reenviar o token mantém o atual; remove o segredo do webhook.
    mpSettings($this, 'put', ['remove_webhook_secret' => true])->assertOk()->assertJsonPath('data.has_webhook_secret', false);
    expect(inTenant($this->a, fn () => IntegrationConnection::query()->sole()->accessToken()))->toBe($this->token);
});

it('token recusado pelo provedor ou em formato inválido não é gravado', function () {
    mpSettings($this, 'put', ['access_token' => 'TEST-token-que-o-provedor-nao-conhece-000'])
        ->assertStatus(422)->assertJsonPath('code', 'invalid_provider_credentials');
    mpSettings($this, 'put', ['access_token' => 'chave-qualquer'])
        ->assertStatus(422)->assertJsonPath('code', 'validation_failed');

    expect(inTenant($this->a, fn () => IntegrationConnection::query()->count()))->toBe(0);
});

it('desconectar apaga as credenciais', function () {
    mpSettings($this, 'put', ['access_token' => $this->token])->assertOk();
    mpSettings($this, 'delete')->assertOk()->assertJsonPath('data.connected', false);

    $connection = inTenant($this->a, fn () => IntegrationConnection::query()->sole());
    expect($connection->credentials)->toBeNull()->and($connection->status)->toBe('disabled');
});

it('gerente e atendente não mexem nas credenciais', function () {
    foreach ([Role::MANAGER, Role::ATTENDANT] as $role) {
        $session = merchantLogin($this->a, makeMember($this->a, role: $role));
        mpSettings($this, 'get', session: $session)->assertForbidden();
        mpSettings($this, 'put', ['access_token' => $this->token], $session)->assertForbidden();
    }
    expect($this->mp->requestsTo('/users/me'))->toBe([]);
});

it('isolamento: a conexão do tenant A não existe para o B', function () {
    mpSettings($this, 'put', ['access_token' => $this->token])->assertOk();

    // A sessão de A não vale no host de B.
    withSession($this->session)->getJson(tenantUrl($this->b, 'merchant/payments/mercadopago'))->assertUnauthorized();
    $this->getJson(tenantUrl($this->b, 'storefront'))->assertJsonCount(2, 'data.payment_methods');

    $sessionB = merchantLogin($this->b, makeMember($this->b));
    withSession($sessionB)->getJson(tenantUrl($this->b, 'merchant/payments/mercadopago'))
        ->assertOk()->assertJsonPath('data.connected', false);
});
