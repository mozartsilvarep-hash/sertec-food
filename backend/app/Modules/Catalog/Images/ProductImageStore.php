<?php

namespace App\Modules\Catalog\Images;

use App\Modules\Catalog\Models\Product;
use App\Modules\Tenancy\TenantContext;
use App\Support\Images\WebpEncoder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Fotos de produto: validação e reencode em WebpEncoder (SECURITY.md);
 * nome gerado pelo servidor, sob o prefixo do tenant.
 */
final class ProductImageStore
{
    public const DISK = 'public';

    public const MIN_SIDE_INPUT = 200;

    public const MAX_SIDE_OUTPUT = 1200;

    /** Miniatura para a lista do cardápio (até ~120 px na tela, nítida em telas 3x). */
    public const THUMB_SIDE = 480;

    public function store(Product $product, UploadedFile $file): string
    {
        $binary = WebpEncoder::encode($file, 'image', self::MIN_SIDE_INPUT, self::MAX_SIDE_OUTPUT);
        $thumb = WebpEncoder::encode($file, 'image', self::MIN_SIDE_INPUT, self::THUMB_SIDE, null, 78);

        $tenant = app(TenantContext::class)->get();
        $key = 'catalog/'.$tenant->public_id.'/'.Str::lower((string) Str::ulid()).'.webp';

        Storage::disk(self::DISK)->put($key, $binary, ['visibility' => 'public']);
        Storage::disk(self::DISK)->put(self::thumbKey($key), $thumb, ['visibility' => 'public']);

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

    /** Miniatura da foto (lista do cardápio e destaques). */
    public static function thumbUrl(?string $key): ?string
    {
        return $key === null ? null : '/storage/'.self::thumbKey($key);
    }

    public static function thumbKey(string $key): string
    {
        return (string) preg_replace('/\.webp$/', '-sm.webp', $key);
    }

    private function delete(?string $key): void
    {
        if ($key !== null && str_starts_with($key, 'catalog/')) {
            Storage::disk(self::DISK)->delete([$key, self::thumbKey($key)]);
        }
    }
}
