<?php

namespace App\Modules\Ordering\Support;

/**
 * Celular/telefone brasileiro: guarda só dígitos com DDD (10 ou 11 dígitos).
 */
final class Phone
{
    public static function normalize(string $raw): ?string
    {
        $digits = (string) preg_replace('/\D+/', '', $raw);

        if (str_starts_with($digits, '55') && in_array(strlen($digits), [12, 13], true)) {
            $digits = substr($digits, 2);
        }

        if (! preg_match('/^[1-9][1-9]\d{8,9}$/', $digits)) {
            return null;
        }

        // 11 dígitos = celular: precisa começar com 9 após o DDD.
        if (strlen($digits) === 11 && $digits[2] !== '9') {
            return null;
        }

        return $digits;
    }

    /** Para exibir ao restaurante: (82) 99999-0000 */
    public static function format(string $digits): string
    {
        return strlen($digits) === 11
            ? sprintf('(%s) %s-%s', substr($digits, 0, 2), substr($digits, 2, 5), substr($digits, 7))
            : sprintf('(%s) %s-%s', substr($digits, 0, 2), substr($digits, 2, 4), substr($digits, 6));
    }

    /** Para o acompanhamento público: (82) •••••-0000 */
    public static function mask(string $digits): string
    {
        return sprintf('(%s) •••••-%s', substr($digits, 0, 2), substr($digits, -4));
    }
}
