<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Http\Errors\ApiException;
use App\Modules\Identity\Auth\CredentialVerifier;
use App\Modules\Identity\Http\Requests\LoginRequest;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\SessionKeys;
use App\Modules\Platform\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AdminAuthController
{
    public function __construct(
        private readonly CredentialVerifier $credentials,
        private readonly AuditLogger $audit,
    ) {}

    public function login(LoginRequest $request): JsonResponse
    {
        $user = $this->credentials->verify($request->email(), $request->password());

        if ($user === null || ! $user->isPlatformStaff()) {
            $this->audit->record('auth.platform.login_failed', metadata: [
                'email_hash' => hash('sha256', $request->email()),
            ]);

            throw ApiException::invalidCredentials();
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $request->session()->put(SessionKeys::SCOPE, SessionKeys::SCOPE_PLATFORM);

        $user->forceFill(['last_login_at' => now()])->save();
        $this->audit->record('auth.platform.login', $user, actor: $user);

        return $this->present($user);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($user instanceof User) {
            $this->audit->record('auth.platform.logout', $user, actor: $user);
        }

        return new JsonResponse(null, 204);
    }

    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return $this->present($user);
    }

    private function present(User $user): JsonResponse
    {
        return new JsonResponse(['data' => [
            'user' => [
                'id' => $user->public_id,
                'name' => $user->name,
                'email' => $user->email,
                'platform_role' => $user->platform_role?->value,
            ],
        ]]);
    }
}
