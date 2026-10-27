<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CatalogFixture;

beforeEach(function () {
    Storage::fake('public');
    $this->a = makeTenant('tenant-a');
    $this->b = makeTenant('tenant-b');
    $this->fa = CatalogFixture::create($this->a);
    $this->fb = CatalogFixture::create($this->b);
    $this->session = merchantLogin($this->a, makeMember($this->a));
});

function pngFile(int $w, int $h, string $name = 'foto.png'): UploadedFile
{
    $img = imagecreatetruecolor($w, $h);
    imagefill($img, 0, 0, imagecolorallocate($img, 200, 60, 20));
    $path = tempnam(sys_get_temp_dir(), 'img');
    imagepng($img, $path);

    return new UploadedFile($path, $name, 'image/png', null, true);
}

function jpegWithExif(): UploadedFile
{
    $img = imagecreatetruecolor(400, 300);
    $path = tempnam(sys_get_temp_dir(), 'jpg');
    imagejpeg($img, $path);
    // Injeta um segmento APP1 "Exif" com texto rastreável após o SOI.
    $jpeg = file_get_contents($path);
    $payload = "Exif\0\0GPS-SEGREDO-123";
    $app1 = "\xFF\xE1".pack('n', strlen($payload) + 2).$payload;
    file_put_contents($path, substr($jpeg, 0, 2).$app1.substr($jpeg, 2));

    return new UploadedFile($path, 'foto.jpg', 'image/jpeg', null, true);
}

function upload(object $test, string $productId, UploadedFile $file)
{
    return withSession($test->session)->post(tenantUrl($test->a, 'merchant/products/'.$productId.'/image'), ['image' => $file], ['Accept' => 'application/json']);
}

it('aceita PNG, reencoda para WebP sob o prefixo do tenant e reduz para 1200 px', function () {
    $response = upload($this, $this->fa->pizza->public_id, pngFile(2400, 1600))->assertOk();

    $url = $response->json('data.image_url');
    expect($url)->toStartWith('/storage/catalog/'.$this->a->public_id.'/')->toEndWith('.webp');

    $key = substr($url, strlen('/storage/'));
    Storage::disk('public')->assertExists($key);
    $info = getimagesizefromstring(Storage::disk('public')->get($key));
    expect($info['mime'])->toBe('image/webp')->and($info[0])->toBe(1200)->and($info[1])->toBe(800);

    // Miniatura para a lista do cardápio.
    $thumb = $response->json('data.thumb_url');
    expect($thumb)->toBe(str_replace('.webp', '-sm.webp', $url));
    $small = getimagesizefromstring(Storage::disk('public')->get(substr($thumb, strlen('/storage/'))));
    expect([$small[0], $small[1]])->toBe([480, 320]);
});

it('remove metadados EXIF (ex.: GPS) ao reencodar', function () {
    $response = upload($this, $this->fa->pizza->public_id, jpegWithExif())->assertOk();
    $key = substr($response->json('data.image_url'), strlen('/storage/'));

    expect(Storage::disk('public')->get($key))->not->toContain('GPS-SEGREDO-123');
});

it('recusa arquivo que não é imagem mesmo com extensão de imagem', function () {
    $fake = UploadedFile::fake()->createWithContent('foto.jpg', '<?php echo "pwned"; ?>');

    upload($this, $this->fa->pizza->public_id, $fake)
        ->assertStatus(422)->assertJsonPath('details.fields.image.0', 'Envie uma imagem JPG, PNG ou WebP.');
    expect(Storage::disk('public')->allFiles())->toBe([]);
});

it('recusa imagem pequena demais ou com dimensões gigantes', function () {
    upload($this, $this->fa->pizza->public_id, pngFile(100, 100))->assertStatus(422);
    upload($this, $this->fa->pizza->public_id, pngFile(6100, 300))->assertStatus(422);
});

it('trocar a foto apaga a anterior; excluir remove o arquivo', function () {
    $first = substr(upload($this, $this->fa->pizza->public_id, pngFile(400, 400))->json('data.image_url'), 9);
    $second = substr(upload($this, $this->fa->pizza->public_id, pngFile(500, 500))->json('data.image_url'), 9);

    Storage::disk('public')->assertMissing($first);
    Storage::disk('public')->assertExists($second);

    withSession($this->session)->deleteJson(tenantUrl($this->a, 'merchant/products/'.$this->fa->pizza->public_id.'/image'))->assertNoContent();
    Storage::disk('public')->assertMissing($second);
});

it('ISOLAMENTO: não envia foto para produto de outro tenant', function () {
    upload($this, $this->fb->pizza->public_id, pngFile(400, 400))->assertNotFound();
    expect(Storage::disk('public')->allFiles())->toBe([]);
});
