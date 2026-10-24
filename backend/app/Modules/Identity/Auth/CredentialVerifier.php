<?php

namespace App\Modules\Identity\Auth;

use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * Verifica e-mail/senha com tempo de resposta uniforme (evita enumerar
 * e-mails pela latência quando o usuário não existe).
 */
final class CredentialVerifier
{
    private static ?string $dummyHash = null;

    public function verify(string $email, string $password): ?User
    {
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            Hash::check($password, self::$dummyHash ??= Hash::make('sertec-timing-equalizer'));

            return null;
        }

        if (! Hash::check($password, $user->getAuthPassword()) || ! $user->active) {
            return null;
        }

        return $user;
    }
}
