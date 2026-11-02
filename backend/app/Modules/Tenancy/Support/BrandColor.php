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
        return self::contrast($hex, '#ffffff');
    }

    /** Razão de contraste WCAG entre duas cores (1 a 21). */
    public static function contrast(string $a, string $b): float
    {
        [$light, $dark] = [max(self::luminance($a), self::luminance($b)), min(self::luminance($a), self::luminance($b))];

        return ($light + 0.05) / ($dark + 0.05);
    }

    public static function isAccessible(string $hex): bool
    {
        return self::contrastWithWhite($hex) >= self::MIN_CONTRAST;
    }

    public static function luminance(string $hex): float
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
