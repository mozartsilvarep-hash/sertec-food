<?php

namespace App\Modules\Identity\Http\Middleware;

use App\Http\Errors\ApiException;
use App\Modules\Identity\Access\TenantAccess;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\SessionKeys;
use App\Modules\Tenancy\TenantContext;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exige sessão de painel do restaurante emitida PARA ESTE tenant.
 * Uma sessão do tenant A reapresentada no host de B é encerrada (401),
 * mesmo que o usuário também seja membro de B.
 */
class EnsureMerchantSession
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly TenantAccess $access,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $request->hasSession()) {
            throw new AuthenticationException;
        }

        $session = $request->session();
        $boundTenant = $session->get(SessionKeys::TENANT_ID);

        if ($session->get(SessionKeys::SCOPE) !== SessionKeys::SCOPE_MERCHANT || $boundTenant !== $this->context->id()) {
            Auth::guard('web')->logout();
            $session->invalidate();

            throw new AuthenticationException;
        }

        if (! $this->access->for($user)->isMember) {
            throw ApiException::forbidden();
        }

        return $next($request);
    }
}
