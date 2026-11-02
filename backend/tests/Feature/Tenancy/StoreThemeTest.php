<?php

use App\Modules\Identity\Models\Role;
use App\Modules\Tenancy\Http\Controllers\StorefrontShellController;
use App\Modules\Tenancy\Support\StoreTheme;
use App\Support\Http\PublicHostResolver;
use App\Support\Http\SystemPublicHostResolver;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CatalogFixture;

beforeEach(function () {
    Storage::fake('public');
    $this->a = makeTenant('tenant-a');
    $this->b = makeTenant('tenant-b');
    CatalogFixture::create($this->a);
    CatalogFixture::create($this->b);
    $this->session = merchantLogin($this->a, makeMember($this->a));
    $this->hosts = new class implements PublicHostResolver
    {
        /** @var array<string, string|null> */
        public array $map = ['yriscakes.com.br' => '203.0.113.10'];

        public function resolve(string $host): ?string
        {
            return $this->map[$host] ?? null;
        }
    };
    app()->instance(PublicHostResolver::class, $this->hosts);
});

function themeTile(int $w, int $h): UploadedFile
{
    $img = imagecreatetruecolor($w, $h);
    imagefill($img, 0, 0, imagecolorallocate($img, 169, 205, 238));
    $path = tempnam(sys_get_temp_dir(), 'tile');
    imagepng($img, $path);

    return new UploadedFile($path, 'padrao.png', 'image/png', null, true);
}

/** Tema da Yris Cakes, igual ao site yriscakes.com.br. */
function yrisTheme(): array
{
    return [
        'colors' => ['canvas' => '#FFF7EE', 'surface' => '#ffffff', 'ink' => '#4a2c23', 'muted' => '#6b4c40', 'line' => '#e9d9c9', 'accent' => '#a9cdee'],
        'fonts' => ['display' => 'gilda-display', 'body' => 'jost', 'script' => 'great-vibes'],
        'radius' => 'round',
    ];
}

it('salva o tema e mostra no cardápio só do próprio restaurante', function () {
    withSession($this->session)->patchJson(tenantUrl($this->a, 'merchant/settings'), ['brand_color' => '#4a2c23', 'theme' => yrisTheme()])
        ->assertOk()
        ->assertJsonPath('data.theme.colors.canvas', '#fff7ee')
        ->assertJsonPath('data.theme.fonts.display', 'gilda-display')
        ->assertJsonPath('data.theme.radius', 'round');

    $this->getJson(tenantUrl($this->a, 'storefront'))->assertOk()
        ->assertJsonPath('data.theme.colors.accent', '#a9cdee')
        ->assertJsonPath('data.theme.fonts.script', 'great-vibes')
        ->assertJsonPath('data.pattern_url', null);
    // Outro restaurante continua com o padrão.
    $this->getJson(tenantUrl($this->b, 'storefront'))
        ->assertJsonPath('data.theme.colors.canvas', '#fafaf9')
        ->assertJsonPath('data.theme.fonts.display', 'bricolage-grotesque')
        ->assertJsonPath('data.theme.radius', 'soft');

    // Remover o tema volta ao padrão.
    withSession($this->session)->patchJson(tenantUrl($this->a, 'merchant/settings'), ['theme' => null])
        ->assertOk()->assertJsonPath('data.theme.colors.canvas', '#fafaf9');
});

it('recusa cor inválida, fonte fora da lista e canto desconhecido', function () {
    $url = tenantUrl($this->a, 'merchant/settings');
    withSession($this->session)->patchJson($url, ['theme' => ['colors' => ['canvas' => 'red']]])
        ->assertStatus(422)->assertJsonPath('details.fields', ['theme.colors.canvas' => ['Cor inválida: use o formato #RRGGBB.']]);
    $fields = withSession($this->session)->patchJson($url, ['theme' => ['fonts' => ['display' => 'comic-sans', 'body' => 'url(https://evil)']]])
        ->assertStatus(422)->json('details.fields');
    expect($fields)->toBe(['theme.fonts.display' => ['Fonte não disponível.'], 'theme.fonts.body' => ['Fonte não disponível.']]);
    withSession($this->session)->patchJson($url, ['theme' => ['radius' => 'blob']])->assertStatus(422);
});

it('exige contraste AA do texto sobre o fundo e os cartões', function () {
    $url = tenantUrl($this->a, 'merchant/settings');
    // Texto claro sobre fundo creme: ilegível.
    expect(withSession($this->session)->patchJson($url, ['theme' => ['colors' => ['canvas' => '#fff7ee', 'ink' => '#c9a28f']]])
        ->assertStatus(422)->json('details.fields'))->toHaveKey('theme.colors.ink');
    // Texto suave claro demais sobre cartão branco.
    expect(withSession($this->session)->patchJson($url, ['theme' => ['colors' => ['muted' => '#a8a29e']]])
        ->assertStatus(422)->json('details.fields'))->toHaveKey('theme.colors.muted');
    // Fundo escuro com texto escuro (o padrão): recusado.
    withSession($this->session)->patchJson($url, ['theme' => ['colors' => ['canvas' => '#1c1917']]])->assertStatus(422);

    expect(StoreTheme::contrastErrors(yrisTheme()['colors']))->toBe([]);
});

it('atendente não altera o tema', function () {
    $member = makeMember($this->a, null, Role::ATTENDANT);
    withSession(merchantLogin($this->a, $member))->patchJson(tenantUrl($this->a, 'merchant/settings'), ['theme' => yrisTheme()])
        ->assertForbidden();
});

it('padronagem é um azulejo quadrado em WebP', function () {
    $tile = withSession($this->session)->post(tenantUrl($this->a, 'merchant/settings/pattern'), ['image' => themeTile(900, 700)], ['Accept' => 'application/json'])
        ->assertOk()->json('data.pattern_url');
    expect($tile)->toStartWith('/storage/brand/'.$this->a->public_id.'/pattern-')->toEndWith('.webp');
    $info = getimagesizefromstring(Storage::disk('public')->get(substr($tile, 9)));
    expect([$info[0], $info[1]])->toBe([600, 600]);
    $this->getJson(tenantUrl($this->a, 'storefront'))->assertJsonPath('data.pattern_url', $tile);
    $this->getJson(tenantUrl($this->b, 'storefront'))->assertJsonPath('data.pattern_url', null);

    withSession($this->session)->deleteJson(tenantUrl($this->a, 'merchant/settings/pattern'))->assertOk()->assertJsonPath('data.pattern_url', null);
    Storage::disk('public')->assertMissing(substr($tile, 9));
});

it('o HTML do cardápio já sai com as cores do tema, sem nada fora do padrão', function () {
    $css = StoreTheme::cssVariables(StoreTheme::parse(yrisTheme())['theme'], '#4a2c23');
    expect($css)->toBe(':root{--theme-canvas:#fff7ee;--theme-surface:#ffffff;--theme-ink:#4a2c23;--theme-muted:#6b4c40;--theme-line:#e9d9c9;--theme-accent:#a9cdee;--brand-600:#4a2c23}');

    $meta = ['title' => 'Yris', 'description' => '', 'image' => null, 'url' => null, 'color' => null, 'robots' => 'index'];
    $html = '<html><head><title>x</title></head></html>';
    expect(StorefrontShellController::inject($html, [...$meta, 'css' => $css]))->toContain('<style id="tema-loja">'.$css.'</style>')
        ->and(StorefrontShellController::inject($html, [...$meta, 'css' => ':root{--x:1}</style><script>alert(1)</script>']))->not->toContain('<script>')
        // Valor salvo fora do formato (ex.: banco editado à mão) não entra no CSS.
        ->and(StoreTheme::cssVariables(['colors' => ['canvas' => 'red;}body{display:none']], null))->toBe('');
});

it('importa o tema.json de um site autorizado sem salvar', function () {
    $this->a->forceFill(['feed_origins_json' => ['https://yriscakes.com.br']])->save();
    Http::fake(['https://yriscakes.com.br/tema.json' => Http::response([
        'versao' => 1,
        'cores' => ['principal' => '#4A2C23', 'fundo' => '#FFF7EE', 'texto' => '#4A2C23', 'textoSuave' => '#6B4C40', 'linha' => '#E9D9C9', 'destaque' => '#A9CDEE', 'superficie' => '#FFFFFF'],
        'fontes' => ['titulos' => 'gilda-display', 'texto' => 'jost', 'assinatura' => 'great-vibes'],
        'cantos' => 'redondo',
    ])]);

    withSession($this->session)->postJson(tenantUrl($this->a, 'merchant/settings/theme/import'), ['origin' => 'https://yriscakes.com.br/'])
        ->assertOk()
        ->assertJsonPath('data.brand_color', '#4a2c23')
        ->assertJsonPath('data.theme.colors.canvas', '#fff7ee')
        ->assertJsonPath('data.theme.fonts.body', 'jost')
        ->assertJsonPath('data.theme.radius', 'round')
        ->assertJsonPath('data.warnings', []);
    withSession($this->session)->getJson(tenantUrl($this->a, 'merchant/settings'))->assertJsonPath('data.site_origins', ['https://yriscakes.com.br']);

    // Só propõe: o tema salvo continua o padrão até o PATCH.
    $this->getJson(tenantUrl($this->a, 'storefront'))->assertJsonPath('data.theme.colors.canvas', '#fafaf9');
    Http::assertSentCount(1);
});

it('importação descarta fonte desconhecida e avisa cor principal clara demais', function () {
    $this->a->forceFill(['feed_origins_json' => ['https://yriscakes.com.br']])->save();
    Http::fake(['*' => Http::response(['versao' => 1, 'cores' => ['principal' => '#A9CDEE'], 'fontes' => ['titulos' => 'fonte-pirata']])]);

    $data = withSession($this->session)->postJson(tenantUrl($this->a, 'merchant/settings/theme/import'), ['origin' => 'https://yriscakes.com.br'])
        ->assertOk()->json('data');
    expect($data['brand_color'])->toBeNull()
        ->and($data['theme']['fonts'])->toBe([])
        ->and($data['warnings'])->toHaveCount(2);
});

it('importação só busca sites autorizados, com https e IP público', function () {
    Http::fake();
    $url = tenantUrl($this->a, 'merchant/settings/theme/import');

    // Site não cadastrado em "Sites autorizados".
    expect(withSession($this->session)->postJson($url, ['origin' => 'https://yriscakes.com.br'])
        ->assertStatus(422)->json('details.fields'))->toHaveKey('origin');

    // Cadastrado, mas o DNS aponta para rede interna (o resolvedor recusa).
    $this->a->forceFill(['feed_origins_json' => ['https://intranet.exemplo.com.br', 'https://yriscakes.com.br']])->save();
    $this->hosts->map['intranet.exemplo.com.br'] = null;
    withSession($this->session)->postJson($url, ['origin' => 'https://intranet.exemplo.com.br'])
        ->assertStatus(422)->assertJsonPath('details.fields.origin.0', 'Não foi possível encontrar o site (DNS) ou ele aponta para uma rede interna.');

    // Outro restaurante não usa os sites autorizados de A.
    $sessionB = merchantLogin($this->b, makeMember($this->b));
    withSession($sessionB)->postJson(tenantUrl($this->b, 'merchant/settings/theme/import'), ['origin' => 'https://yriscakes.com.br'])
        ->assertStatus(422);

    Http::assertNothingSent();

    expect(SystemPublicHostResolver::isPublic('10.0.0.5'))->toBeFalse()
        ->and(SystemPublicHostResolver::isPublic('127.0.0.1'))->toBeFalse()
        ->and(SystemPublicHostResolver::isPublic('169.254.169.254'))->toBeFalse()
        ->and(SystemPublicHostResolver::isPublic('::1'))->toBeFalse()
        ->and(SystemPublicHostResolver::isPublic('144.217.162.154'))->toBeTrue();
});

it('importação recusa resposta fora do contrato ou redirecionamento', function () {
    $this->a->forceFill(['feed_origins_json' => ['https://yriscakes.com.br']])->save();
    $url = tenantUrl($this->a, 'merchant/settings/theme/import');

    Http::fake(['*' => Http::sequence()
        ->push('', 302, ['Location' => 'http://169.254.169.254/'])
        ->push(['versao' => 2])
        ->push('<html>não é json</html>', 200),
    ]);
    withSession($this->session)->postJson($url, ['origin' => 'https://yriscakes.com.br'])
        ->assertStatus(422)->assertJsonPath('details.fields.origin.0', 'O site respondeu 302 em /tema.json. Publique o arquivo do tema no site.');
    withSession($this->session)->postJson($url, ['origin' => 'https://yriscakes.com.br'])->assertStatus(422);
    withSession($this->session)->postJson($url, ['origin' => 'https://yriscakes.com.br'])->assertStatus(422);
});

it('as fontes do servidor são as mesmas do app (theme.ts)', function () {
    $ts = (string) file_get_contents(base_path('../frontend/packages/ui/src/theme.ts'));
    preg_match_all("/\\{ key: '([a-z0-9-]+)'.*?roles: \\[([^\\]]*)\\]/", $ts, $m, PREG_SET_ORDER);
    $byRole = ['display' => [], 'body' => [], 'script' => []];
    foreach ($m as [, $key, $roles]) {
        foreach (array_map(fn ($r) => trim($r, " '"), explode(',', $roles)) as $role) {
            $byRole[$role][] = $key;
        }
    }
    expect($byRole['display'])->toEqualCanonicalizing(StoreTheme::DISPLAY_FONTS)
        ->and($byRole['body'])->toEqualCanonicalizing(StoreTheme::BODY_FONTS)
        ->and($byRole['script'])->toEqualCanonicalizing(StoreTheme::SCRIPT_FONTS);
});
