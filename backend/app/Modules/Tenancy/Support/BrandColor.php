<?php

namespace App\Modules\Tenancy\Support;

/**
 * Cor da marca: precisa ter contraste mínimo AA (4,5:1) com texto branco,
 * porque botões e capa usam texto branco sobre ela (DESIGN.md).
 */
final class BrandColor
{
    public const MIN_CONTRAST = 4.5;

    public static function contrastWithWhite(string $hex): float
    {
        $luminance = self::luminance($hex);

        return (1.0 + 0.05) / ($luminance + 0.05);
    }

    public static function isAccessible(string $hex): bool
    {
        return self::contrastWithWhite($hex) >= self::MIN_CONTRAST;
    }

    private static function luminance(string $hex): float
    {
        $hex = ltrim($hex, '#');
        $channels = array_map(
            fn (string $c) => hexdec($c) / 255,
            [substr($hex, 0, 2), substr($hex, 2, 2), substr($hex, 4, 2)]
        );

        [$r, $g, $b] = array_map(
            fn (float $c) => $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4,
            $channels
        );

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }
}
