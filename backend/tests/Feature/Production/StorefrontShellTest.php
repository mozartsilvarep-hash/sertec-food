<?php

use App\Modules\Platform\Ops\HealthCheck;
use App\Modules\Tenancy\Enums\TenantStatus;

beforeEach(function () {
    $this->index = tempnam(sys_get_temp_dir(), 'idx');
    file_put_contents($this->index, "<!doctype html><html><head>\n<meta name=\"description\" content=\"Cardápio digital\" />\n<title>Cardápio · SerTec Food</title>\n</head><body><div id=\"app\"></div></body></html>");
    config(['tenancy.storefront_index' => $this->index]);
});

afterEach(fn () => @unlink($this->index));

it('entrega o HTML do cardápio com título, descrição e Open Graph do restaurante (escapados)', function () {
    $t = makeTenant('pizzaria-sol');
    $t->update(['name' => 'Pizzaria "Sol" & Mar', 'description' => 'Forno a lenha <desde 1990>', 'brand_color' => '#14532d']);

    $html = $this->get('http://'.hostOf($t).'/__storefront')->assertOk()
        ->assertHeader('Content-Type', 'text/html; charset=UTF-8')->getContent();

    expect($html)->toContain('<title>Pizzaria &quot;Sol&quot; &amp; Mar · Cardápio e pedidos</title>')
        ->toContain('<meta name="description" content="Forno a lenha &lt;desde 1990&gt;" />')
        ->toContain('<meta property="og:title" content="Pizzaria &quot;Sol&quot; &amp; Mar · Cardápio e pedidos" />')
        ->toContain('<meta name="theme-color" content="#14532d" />')
        ->toContain('<link rel="canonical" href="http://'.hostOf($t).'/" />')
        ->toContain('<div id="app"></div>')
        ->not->toContain('<desde');
});

it('usa a capa como prévia do link e a logo como ícone', function () {
    $t = makeTenant('doceria');
    $t->forceFill(['logo_key' => 'brand/x/logo-a.webp', 'cover_key' => 'brand/x/cover-b.webp'])->save();

    $html = $this->get('http://'.hostOf($t).'/__storefront')->assertOk()->getContent();
    expect($html)->toContain('<meta property="og:image" content="http://'.hostOf($t).'/storage/brand/x/cover-b.webp" />')
        ->toContain('<link rel="icon" href="http://'.hostOf($t).'/storage/brand/x/logo-a.webp" type="image/webp" />');
});

it('host desconhecido ou estabelecimento suspenso recebe o app sem indexação', function () {
    $this->get('http://nao-existe.'.config('tenancy.base_domain').'/__storefront')->assertNotFound()
        ->assertSee('noindex', false);

    $t = makeTenant('pizzaria-sol', TenantStatus::Suspended);
    $this->get('http://'.hostOf($t).'/__storefront')->assertOk()->assertSee('Cardápio indisponível', false)->assertSee('noindex', false);
});

it('cliente não consegue forjar o IP por X-Forwarded-For (nenhum proxy confiável por padrão)', function () {
    $t = makeTenant('pizzaria-sol');
    // 240/min por IP: com XFF variando, o limite continua valendo para o IP real.
    for ($i = 0; $i < 240; $i++) {
        $this->withHeader('X-Forwarded-For', "10.0.0.{$i}")->getJson(tenantUrl($t, 'storefront'))->assertOk();
    }
    $this->withHeader('X-Forwarded-For', '10.9.9.9')->getJson(tenantUrl($t, 'storefront'))->assertStatus(429);
});

it('saúde: aponta agendador parado e fica verde com batimento', function () {
    $this->getJson('http://localhost/api/v1/internal/health')->assertStatus(503)
        ->assertJsonPath('checks.database.ok', true)
        ->assertJsonPath('checks.scheduler.ok', false);

    HealthCheck::beat();
    $this->getJson('http://localhost/api/v1/internal/health')->assertOk()->assertJsonPath('ok', true);
    $this->artisan('ops:status')->assertSuccessful();
});
