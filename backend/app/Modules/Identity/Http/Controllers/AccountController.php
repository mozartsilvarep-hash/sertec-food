<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Errors\ApiException;
use App\Modules\Identity\Auth\SessionTerminator;
use App\Modules\Identity\Models\User;
use App\Modules\Platform\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

/**
 * POST /merchant/me/password — o próprio usuário troca a senha (ex.: depois
 * de receber uma senha temporária). Encerra as outras sessões.
 */
class AccountController
{
    public function password(Request $request, AuditLogger $audit): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', Password::min(10)->letters()->numbers(), 'different:current_password'],
        ], [], ['password' => 'nova senha', 'current_password' => 'senha atual']);

        if (! Hash::check($data['current_password'], $user->password)) {
            throw new ApiException(422, 'current_password_invalid', 'Senha atual incorreta.');
        }

        $user->forceFill(['password' => $data['password'], 'remember_token' => null])->save();
        $request->session()->regenerate();
        SessionTerminator::forUser($user->id, $request->session()->getId());
        $audit->record('auth.password_changed', $user, actor: $user);

        return new JsonResponse(null, 204);
    }
}
