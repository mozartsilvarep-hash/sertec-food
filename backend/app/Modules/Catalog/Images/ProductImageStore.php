<?php

namespace App\Modules\Catalog\Images;

use App\Modules\Catalog\Models\Product;
use App\Modules\Tenancy\TenantContext;
use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Upload seguro de fotos de produto (SECURITY.md):
 * - tipo verificado pelo CONTEÚDO (finfo + getimagesize), não pela extensão;
 * - limites de tamanho de arquivo e de dimensões (evita "bombas" de pixels);
 * - a imagem é decodificada e REENCODADA em WebP: descarta EXIF (GPS),
 *   perfis, comentários e qualquer payload embutido (polyglot);
 * - nome gerado pelo servidor, sob o prefixo do tenant.
 */
final class ProductImageStore
{
    public const DISK = 'public';

    public const MAX_BYTES = 5 * 1024 * 1024;

    public const MAX_SIDE_INPUT = 6000;

    public const MIN_SIDE_INPUT = 200;

    public const MAX_SIDE_OUTPUT = 1200;

    private const ALLOWED = [
        'image/jpeg' => IMAGETYPE_JPEG,
        'image/png' => IMAGETYPE_PNG,
        'image/webp' => IMAGETYPE_WEBP,
    ];

    public function store(Product $product, UploadedFile $file): string
    {
        $path = $file->getRealPath();
        if ($path === false || ! $file->isValid()) {
            $this->fail('Não foi possível ler o arquivo enviado.');
        }

        if ($file->getSize() > self::MAX_BYTES) {
            $this->fail('A imagem deve ter no máximo 5 MB.');
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        $info = @getimagesize($path);

        if (! isset(self::ALLOWED[$mime]) || $info === false || $info[2] !== self::ALLOWED[$mime]) {
            $this->fail('Envie uma imagem JPG, PNG ou WebP.');
        }

        [$width, $height] = $info;
        if ($width > self::MAX_SIDE_INPUT || $height > self::MAX_SIDE_INPUT) {
            $this->fail('A imagem é grande demais (máximo 6000 px de lado).');
        }
        if ($width < self::MIN_SIDE_INPUT || $height < self::MIN_SIDE_INPUT) {
            $this->fail('A imagem é pequena demais (mínimo 200 px de lado).');
        }

        $source = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png' => @imagecreatefrompng($path),
            default => @imagecreatefromwebp($path),
        };

        if (! $source instanceof GdImage) {
            $this->fail('A imagem está corrompida.');
        }

        $output = $this->resize($source, $width, $height);
        ob_start();
        imagewebp($output, null, 82);
        $binary = (string) ob_get_clean();

        $tenant = app(TenantContext::class)->get();
        $key = 'catalog/'.$tenant->public_id.'/'.Str::lower((string) Str::ulid()).'.webp';

        Storage::disk(self::DISK)->put($key, $binary, ['visibility' => 'public']);

        $old = $product->image_key;
        $product->forceFill(['image_key' => $key])->save();
        $this->delete($old);

        return $key;
    }

    public function remove(Product $product): void
    {
        $this->delete($product->image_key);
        $product->forceFill(['image_key' => null])->save();
    }

    /**
     * URL relativa: o arquivo é servido na mesma origem do tenant (Nginx /storage).
     */
    public static function url(?string $key): ?string
    {
        return $key === null ? null : '/storage/'.$key;
    }

    private function delete(?string $key): void
    {
        if ($key !== null && str_starts_with($key, 'catalog/')) {
            Storage::disk(self::DISK)->delete($key);
        }
    }

    private function resize(GdImage $source, int $width, int $height): GdImage
    {
        $scale = min(1, self::MAX_SIDE_OUTPUT / max($width, $height));
        $w = max(1, (int) round($width * $scale));
        $h = max(1, (int) round($height * $scale));

        $canvas = imagecreatetruecolor($w, $h);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $w, $h, $width, $height);

        return $canvas;
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['image' => $message]);
    }
}
