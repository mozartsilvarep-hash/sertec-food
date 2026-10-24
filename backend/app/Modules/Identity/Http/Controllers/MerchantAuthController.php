<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Errors\ApiException;
use App\Modules\Identity\Access\TenantAccess;
use App\Modules\Identity\Auth\CredentialVerifier;
use App\Modules\Identity\Http\Requests\LoginRequest;
use App\Modules\Identity\Http\Resources\MerchantMeResource;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\SessionKeys;
use App\Modules\Platform\Audit\AuditLogger;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Serviços "scoped" (TenantContext, TenantAccess) são recebidos por injeção
 * no MÉTODO: o Laravel reaproveita a instância do controller entre
 * requisições (rotas em cache / Octane), então injetá-los no construtor
 * prenderia o contexto de uma requisição anterior.
 */
class MerchantAuthController
{
    public function login(
        LoginRequest $request,
        TenantContext $context,
        TenantAccess $access,
        CredentialVerifier $credentials,
        AuditLogger $audit,
    ): MerchantMeResource {
        $user = $credentials->verify($request->email(), $request->password());

        // Mesma resposta para "senha errada" e "sem vínculo com este tenant":
        // não revela se o e-mail existe nem em quais estabelecimentos atua.
        if ($user === null || ! $access->for($user)->isMember) {
            $audit->record('auth.merchant.login_failed', metadata: [
                'email_hash' => hash('sha256', $request->email()),
                'reason' => $user === null ? 'invalid_credentials' : 'no_membership',
            ]);

            throw ApiException::invalidCredentials();
        }

        Auth::guard('web')->login($user);
        $session = $request->session();
        $session->regenerate();
        $session->put(SessionKeys::SCOPE, SessionKeys::SCOPE_MERCHANT);
        $session->put(SessionKeys::TENANT_ID, $context->id());

        $user->forceFill(['last_login_at' => now()])->save();
        $audit->record('auth.merchant.login', $user, actor: $user);

        return new MerchantMeResource($user);
    }

    public function logout(Request $request, AuditLogger $audit): JsonResponse
    {
        $user = $request->user();

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($user instanceof User) {
            $audit->record('auth.merchant.logout', $user, actor: $user);
        }

        return new JsonResponse(null, 204);
    }

    public function me(Request $request): MerchantMeResource
    {
        /** @var User $user */
        $user = $request->user();

        return new MerchantMeResource($user);
    }
}
