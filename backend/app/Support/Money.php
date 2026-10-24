<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Dinheiro em centavos inteiros (nunca float). Banco usa DECIMAL(12,2);
 * API recebe/devolve strings "12.34".
 */
final class Money
{
    public static function toCents(string|int|float $amount): int
    {
        $value = is_string($amount) ? trim($amount) : (string) $amount;

        if (! preg_match('/^(-)?(\d+)(?:\.(\d{1,2}))?$/', $value, $m)) {
            throw new InvalidArgumentException("Valor monetário inválido: {$value}");
        }

        $cents = ((int) $m[2]) * 100 + (int) str_pad($m[3] ?? '0', 2, '0');

        return $m[1] === '-' ? -$cents : $cents;
    }

    public static function format(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $abs = abs($cents);

        return $sign.intdiv($abs, 100).'.'.str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * Média com arredondamento "meio para cima" no centavo.
     *
     * @param  list<int>  $values
     */
    public static function averageCents(array $values): int
    {
        $n = count($values);
        if ($n === 0) {
            return 0;
        }

        return intdiv(2 * array_sum($values) + $n, 2 * $n);
    }
}
