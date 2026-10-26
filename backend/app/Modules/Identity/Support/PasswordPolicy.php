<?php

namespace App\Modules\Identity\Support;

use Closure;
use Illuminate\Validation\Rules\Password;

/**
 * Regra de senha escolhida pela SerTec: no mínimo 6 caracteres, com ao menos
 * uma letra maiúscula e um caractere especial. O limite de tentativas de
 * login (throttle:login) é o que segura quem tenta adivinhar.
 */
final class PasswordPolicy
{
    public const MIN = 6;

    public const HINT = 'Mínimo de 6 caracteres, com ao menos 1 letra maiúscula e 1 caractere especial (ex.: ! @ # $ %).';

    /** @return list<mixed> */
    public static function rules(): array
    {
        return [
            Password::min(self::MIN)->symbols(),
            function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_string($value) || preg_match('/\p{Lu}/u', $value) !== 1) {
                    $fail('A :attribute precisa ter ao menos uma letra maiúscula.');
                }
            },
        ];
    }
}
