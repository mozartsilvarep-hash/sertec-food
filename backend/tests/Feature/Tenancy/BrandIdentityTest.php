<?php

use App\Modules\Identity\Models\Role;
use App\Modules\Tenancy\Support\Cnpj;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CatalogFixture;

beforeEach(function () {
    Storage::fake('public');
    $this->a = makeTenant('tenant-a');
    $this->b = makeTenant('tenant-b');
    CatalogFixture::create($this->a);
    CatalogFixture::create($this->b);
    $this->session = merchantLogin($this->a, makeMember($this->a));
});

function brandPng(int $w, int $h): UploadedFile
{
    $img = imagecreatetruecolor($w, $h);
    imagefill($img, 0, 0, imagecolorallocate($img, 120, 30, 60));
    $path = tempnam(sys_get_temp_dir(), 'brand');
    imagepng($img, $path);

    return new UploadedFile($path, 'logo.png', 'image/png', null, true);
}

it('valida CNPJ numérico e alfanumérico', function () {
    expect(Cnpj::isValid('11.222.333/0001-81'))->toBeTrue()
        ->and(Cnpj::isValid('11.222.333/0001-82'))->toBeFalse()
        ->and(Cnpj::isValid('11111111111111'))->toBeFalse()
        ->and(Cnpj::isValid('12.ABC.345/01DE-35'))->toBeTrue()
        ->and(Cnpj::isValid('12.ABC.345/01DE-36'))->toBeFalse()
        ->and(Cnpj::format('11222333000181'))->toBe('11.222.333/0001-81');
});

it('salva identidade da loja e mostra no cardápio só do próprio restaurante', function () {
    $url = tenantUrl($this->a, 'merchant/settings');
    withSession($this->session)->patchJson($url, ['cnpj' => '11.222.333/0001-82'])
        ->assertStatus(422)->assertJsonPath('details.fields.cnpj.0', 'CNPJ inválido. Confira os números.');
    withSession($this->session)->patchJson($url, ['instagram' => 'yris cakes'])->assertStatus(422);
    withSession($this->session)->patchJson($url, ['whatsapp' => '1234'])->assertStatus(422);

    withSession($this->session)->patchJson($url, [
        'legal_name' => ' Yris Doces LTDA ', 'cnpj' => '11222333000181', 'instagram' => '@yriscakes', 'whatsapp' => '(79) 99999-1234',
    ])->assertOk()
        ->assertJsonPath('data.cnpj', '11.222.333/0001-81')
        ->assertJsonPath('data.instagram', 'yriscakes')
        ->assertJsonPath('data.whatsapp', '5579999991234')
        ->assertJsonPath('data.legal_name', 'Yris Doces LTDA');

    $this->getJson(tenantUrl($this->a, 'storefront'))->assertOk()
        ->assertJsonPath('data.cnpj', '11.222.333/0001-81')
        ->assertJsonPath('data.legal_name', 'Yris Doces LTDA')
        ->assertJsonPath('data.logo_url', null);
    $this->getJson(tenantUrl($this->b, 'storefront'))->assertJsonPath('data.cnpj', null)->assertJsonPath('data.instagram', null);
});

it('logo vira quadrada e capa larga em WebP; remover apaga o arquivo; outro restaurante não vê', function () {
    $logo = withSession($this->session)->post(tenantUrl($this->a, 'merchant/settings/logo'), ['image' => brandPng(800, 600)], ['Accept' => 'application/json'])
        ->assertOk()->json('data.logo_url');
    expect($logo)->toStartWith('/storage/brand/'.$this->a->public_id.'/logo-')->toEndWith('.webp');
    $info = getimagesizefromstring(Storage::disk('public')->get(substr($logo, 9)));
    expect([$info[0], $info[1], $info['mime']])->toBe([512, 512, 'image/webp']);

    $cover = withSession($this->session)->post(tenantUrl($this->a, 'merchant/settings/cover'), ['image' => brandPng(2000, 2000)], ['Accept' => 'application/json'])
        ->assertOk()->json('data.cover_url');
    $info = getimagesizefromstring(Storage::disk('public')->get(substr($cover, 9)));
    expect([$info[0], $info[1]])->toBe([1600, 640]);

    withSession($this->session)->post(tenantUrl($this->a, 'merchant/settings/cover'), ['image' => brandPng(300, 300)], ['Accept' => 'application/json'])
        ->assertStatus(422)->assertJsonPath('details.fields.image.0', 'A imagem é pequena demais (mínimo 400 px de lado).');
    withSession($this->session)->post(tenantUrl($this->a, 'merchant/settings/banner'), ['image' => brandPng(800, 600)], ['Accept' => 'application/json'])
        ->assertNotFound();

    $this->getJson(tenantUrl($this->a, 'storefront'))->assertJsonPath('data.logo_url', $logo)->assertJsonPath('data.cover_url', $cover);
    $this->getJson(tenantUrl($this->b, 'storefront'))->assertJsonPath('data.logo_url', null);

    withSession($this->session)->deleteJson(tenantUrl($this->a, 'merchant/settings/logo'))->assertOk()->assertJsonPath('data.logo_url', null);
    Storage::disk('public')->assertMissing(substr($logo, 9));
});

it('atendente sem permissão de configurações não troca a logo', function () {
    $member = makeMember($this->a, null, Role::ATTENDANT);
    withSession(merchantLogin($this->a, $member))->post(tenantUrl($this->a, 'merchant/settings/logo'), ['image' => brandPng(400, 400)], ['Accept' => 'application/json'])
        ->assertForbidden();
});
