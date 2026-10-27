<?php

use App\Modules\Catalog\Images\ProductImageStore;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Miniaturas (até 480 px) das fotos já enviadas: a lista do cardápio deixa
 * de baixar a foto grande de cada produto. Só arquivos: nada muda no banco.
 */
return new class extends Migration
{
    public function up(): void
    {
        $disk = Storage::disk(ProductImageStore::DISK);
        foreach (DB::table('products')->whereNotNull('image_key')->pluck('image_key') as $key) {
            $key = (string) $key;
            $thumb = ProductImageStore::thumbKey($key);
            if ($thumb === $key || ! $disk->exists($key) || $disk->exists($thumb)) {
                continue;
            }
            $source = @imagecreatefromstring((string) $disk->get($key));
            if ($source === false) {
                Log::warning('catalog.thumbnail_failed', ['key' => $key]);

                continue;
            }
            $w = imagesx($source);
            $h = imagesy($source);
            $scale = min(1, ProductImageStore::THUMB_SIDE / max($w, $h));
            $canvas = imagecreatetruecolor(max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale)));
            imagealphablending($canvas, false);
            imagesavealpha($canvas, true);
            imagecopyresampled($canvas, $source, 0, 0, 0, 0, imagesx($canvas), imagesy($canvas), $w, $h);
            ob_start();
            imagewebp($canvas, null, 78);
            $disk->put($thumb, (string) ob_get_clean(), ['visibility' => 'public']);
        }
    }

    public function down(): void
    {
        // Miniaturas são descartáveis; ficam no disco.
    }
};
