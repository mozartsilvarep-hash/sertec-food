<?php

namespace App\Modules\Tenancy\Support;

use App\Modules\Tenancy\Models\Tenant;
use App\Support\Images\WebpEncoder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Logo e capa da loja: mesmo reencode seguro das fotos de produto
 * (WebpEncoder), recortadas na proporção em que aparecem no cardápio.
 */
final class BrandImageStore
{
    public const DISK = 'public';

    /** @var array<string, array{column: string, min: int, max: int, ratio: float}> */
    public const KINDS = [
        'logo' => ['column' => 'logo_key', 'min' => 120, 'max' => 512, 'ratio' => 1.0],
        'cover' => ['column' => 'cover_key', 'min' => 400, 'max' => 1600, 'ratio' => 2.5],
    ];

    public function store(Tenant $tenant, string $kind, UploadedFile $file): string
    {
        $spec = self::KINDS[$kind];
        $binary = WebpEncoder::encode($file, 'image', $spec['min'], $spec['max'], $spec['ratio'], $kind === 'logo' ? 90 : 80);
        $key = 'brand/'.$tenant->public_id.'/'.$kind.'-'.Str::lower((string) Str::ulid()).'.webp';
        Storage::disk(self::DISK)->put($key, $binary, ['visibility' => 'public']);

        $old = $tenant->getAttribute($spec['column']);
        $tenant->forceFill([$spec['column'] => $key])->save();
        $this->delete(is_string($old) ? $old : null);

        return $key;
    }

    public function remove(Tenant $tenant, string $kind): void
    {
        $column = self::KINDS[$kind]['column'];
        $old = $tenant->getAttribute($column);
        $tenant->forceFill([$column => null])->save();
        $this->delete(is_string($old) ? $old : null);
    }

    public static function url(?string $key): ?string
    {
        return $key === null ? null : '/storage/'.$key;
    }

    private function delete(?string $key): void
    {
        if ($key !== null && str_starts_with($key, 'brand/')) {
            Storage::disk(self::DISK)->delete($key);
        }
    }
}
