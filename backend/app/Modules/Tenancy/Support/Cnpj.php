<?php

namespace App\Modules\Tenancy\Support;

/**
 * CNPJ numérico e alfanumérico (IN RFB 2.229/2024, em uso desde jul/2026):
 * 12 posições 0-9/A-Z + 2 dígitos verificadores calculados por módulo 11
 * sobre (código ASCII − 48) de cada caractere.
 */
final class Cnpj
{
    public static function normalize(string $value): string
    {
        return (string) preg_replace('/[^0-9A-Z]/', '', strtoupper($value));
    }

    public static function isValid(string $value): bool
    {
        $c = self::normalize($value);
        if (! preg_match('/^[0-9A-Z]{12}[0-9]{2}$/', $c) || preg_match('/^(\d)\1{13}$/', $c)) {
            return false;
        }

        return substr($c, 12) === self::digits(substr($c, 0, 12));
    }

    public static function format(string $value): string
    {
        $c = self::normalize($value);

        return strlen($c) === 14
            ? substr($c, 0, 2).'.'.substr($c, 2, 3).'.'.substr($c, 5, 3).'/'.substr($c, 8, 4).'-'.substr($c, 12)
            : $c;
    }

    private static function digits(string $base): string
    {
        foreach ([[5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2], [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]] as $weights) {
            $sum = 0;
            foreach ($weights as $i => $w) {
                $sum += (ord($base[$i]) - 48) * $w;
            }
            $r = $sum % 11;
            $base .= $r < 2 ? '0' : (string) (11 - $r);
        }

        return substr($base, 12);
    }
}
