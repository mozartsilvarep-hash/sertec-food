<?php

use App\Modules\Catalog\Models\Promotion;
use Tests\Support\CatalogFixture;

beforeEach(function () {
    $this->a = makeTenant('tenant-a');
    $this->b = makeTenant('tenant-b');
    $this->fa = CatalogFixture::create($this->a);
    $this->fb = CatalogFixture::create($this->b);
    $this->session = merchantLogin($this->a, makeMember($this->a));
});

it('feed v1: promoções vigentes (destaque primeiro), faixa no período e CORS só para o site cadastrado', function () {
    $url = tenantUrl($this->a, 'merchant/promotions');
    withSession($this->session)->postJson($url, ['product_id' => $this->fa->soda->public_id, 'percent' => '20', 'ends_at' => now('America/Sao_Paulo')->addDays(3)->format('Y-m-d\TH:i')])->assertCreated();
    withSession($this->session)->postJson($url, ['product_id' => $this->fa->pizza->public_id, 'promo_price' => '34,90', 'featured' => true, 'badge' => 'Novidade'])->assertCreated();
    withSession($this->session)->putJson(tenantUrl($this->a, 'merchant/storefront-feed'), [
        'announcement' => ['text' => 'Encomendas de Natal abertas', 'link' => 'https://loja.example.com/natal', 'link_label' => 'Ver kits'],
        'feed_origins' => ['https://www.yris.example/'],
    ])->assertOk()->assertJsonPath('data.feed_origins', ['https://www.yris.example']);
    // Promoção do tenant B não aparece.
    inTenant($this->b, fn () => Promotion::query()->create(['product_id' => $this->fb->soda->id, 'percent_bp' => 5000]));

    $res = $this->withHeader('Origin', 'https://www.yris.example')->getJson(tenantUrl($this->a, 'vitrine/promocoes.json'))->assertOk()
        ->assertHeader('Access-Control-Allow-Origin', 'https://www.yris.example')
        ->assertHeader('Vary', 'Origin')
        ->assertJsonPath('versao', 1)
        ->assertJsonPath('loja.aberta', true)
        ->assertJsonPath('faixa.texto', 'Encomendas de Natal abertas')
        ->assertJsonPath('faixa.rotuloLink', 'Ver kits')
        ->assertJsonCount(2, 'promocoes')
        ->assertJsonPath('promocoes.0.titulo', 'Pizza')
        ->assertJsonPath('promocoes.0.precoDe', 40)
        ->assertJsonPath('promocoes.0.precoPor', 34.9)
        ->assertJsonPath('promocoes.0.selo', 'Novidade')
        ->assertJsonPath('promocoes.0.destaque', true)
        ->assertJsonPath('promocoes.1.precoDe', 6.5)
        ->assertJsonPath('promocoes.1.precoPor', 5.2)
        ->assertJsonPath('promocoes.1.selo', '-20%');

    expect($res->json('promocoes.0.url'))->toBe('http://'.hostOf($this->a).'/?produto='.$this->fa->pizza->public_id)
        ->and($res->json('promocoes.1.fim'))->toEndWith('-03:00')
        ->and($res->headers->get('Content-Type'))->toBe('application/json; charset=utf-8')
        ->and($res->headers->get('Cache-Control'))->toContain('max-age=60');

    $this->withHeader('Origin', 'https://site-malicioso.example')->getJson(tenantUrl($this->a, 'vitrine/promocoes.json'))
        ->assertOk()->assertHeaderMissing('Access-Control-Allow-Origin');

    // A faixa também aparece no cardápio.
    $this->getJson(tenantUrl($this->a, 'storefront'))->assertJsonPath('data.announcement.text', 'Encomendas de Natal abertas');
});

it('faixa fora do período some do feed e do cardápio; origem inválida e link sem https são recusados', function () {
    withSession($this->session)->putJson(tenantUrl($this->a, 'merchant/storefront-feed'), [
        'announcement' => ['text' => 'Promo de verão', 'starts_at' => now('America/Sao_Paulo')->addDay()->format('Y-m-d\TH:i')],
    ])->assertOk()->assertJsonPath('data.announcement_active', false);

    $this->getJson(tenantUrl($this->a, 'vitrine/promocoes.json'))->assertOk()->assertJsonMissingPath('faixa')->assertJsonPath('promocoes', []);
    $this->getJson(tenantUrl($this->a, 'storefront'))->assertJsonPath('data.announcement', null);

    withSession($this->session)->putJson(tenantUrl($this->a, 'merchant/storefront-feed'), ['feed_origins' => ['http://inseguro.example']])->assertStatus(422);
    withSession($this->session)->putJson(tenantUrl($this->a, 'merchant/storefront-feed'), ['announcement' => ['text' => 'x', 'link' => 'http://x.example']])->assertStatus(422);
});
