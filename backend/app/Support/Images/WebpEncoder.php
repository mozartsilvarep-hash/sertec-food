<?php

namespace App\Support\Images;

use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * Validação e reencode seguro de imagens enviadas (SECURITY.md):
 * - tipo verificado pelo CONTEÚDO (finfo + getimagesize), não pela extensão;
 * - limites de tamanho de arquivo e de dimensões (evita "bombas" de pixels);
 * - a imagem é decodificada e REENCODADA em WebP: descarta EXIF (GPS),
 *   perfis, comentários e qualquer payload embutido (polyglot).
 *
 * Usado pelas fotos de produto e pela logo/capa da loja.
 */
final class WebpEncoder
{
    public const MAX_BYTES = 5 * 1024 * 1024;

    public const MAX_SIDE_INPUT = 6000;

    private const ALLOWED = [
        'image/jpeg' => IMAGETYPE_JPEG,
        'image/png' => IMAGETYPE_PNG,
        'image/webp' => IMAGETYPE_WEBP,
    ];

    /**
     * @param  float|null  $crop  Proporção largura/altura para recorte central (ex.: 1.0 = quadrado); null mantém.
     */
    public static function encode(UploadedFile $file, string $field, int $minSide, int $maxSide, ?float $crop = null, int $quality = 82): string
    {
        $fail = fn (string $m) => throw ValidationException::withMessages([$field => $m]);
        $path = $file->getRealPath();
        if ($path === false || ! $file->isValid()) {
            $fail('Não foi possível ler o arquivo enviado.');
        }
        if ($file->getSize() > self::MAX_BYTES) {
            $fail('A imagem deve ter no máximo 5 MB.');
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file((string) $path);
        $info = @getimagesize((string) $path);
        if (! isset(self::ALLOWED[$mime]) || $info === false || $info[2] !== self::ALLOWED[$mime]) {
            $fail('Envie uma imagem JPG, PNG ou WebP.');
        }

        [$width, $height] = $info;
        if ($width > self::MAX_SIDE_INPUT || $height > self::MAX_SIDE_INPUT) {
            $fail('A imagem é grande demais (máximo 6000 px de lado).');
        }
        if ($width < $minSide || $height < $minSide) {
            $fail("A imagem é pequena demais (mínimo {$minSide} px de lado).");
        }

        $source = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg((string) $path),
            'image/png' => @imagecreatefrompng((string) $path),
            default => @imagecreatefromwebp((string) $path),
        };
        if (! $source instanceof GdImage) {
            $fail('A imagem está corrompida.');
        }

        // Recorte central na proporção pedida.
        $sx = 0;
        $sy = 0;
        $sw = $width;
        $sh = $height;
        if ($crop !== null) {
            if ($width / $height > $crop) {
                $sw = (int) round($height * $crop);
                $sx = intdiv($width - $sw, 2);
            } else {
                $sh = (int) round($width / $crop);
                $sy = intdiv($height - $sh, 2);
            }
        }

        $scale = min(1, $maxSide / max($sw, $sh));
        $w = max(1, (int) round($sw * $scale));
        $h = max(1, (int) round($sh * $scale));
        $canvas = imagecreatetruecolor($w, $h);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagecopyresampled($canvas, $source, 0, 0, $sx, $sy, $w, $h, $sw, $sh);

        ob_start();
        imagewebp($canvas, null, $quality);

        return (string) ob_get_clean();
    }
}
