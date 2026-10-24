<?php

namespace App\Modules\Platform\Http\Middleware;

use App\Http\Errors\ApiException;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\SessionKeys;
use App\Modules\Tenancy\TenantContext;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exige sessão de plataforma de um usuário da equipe SerTec e executa a
 * requisição em modo plataforma (leitura entre tenants permitida).
 */
class EnsurePlatformSession
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $request->hasSession()) {
            throw new AuthenticationException;
        }

        // Sessão emitida para outro escopo (ex.: painel de restaurante): encerra.
        if ($request->session()->get(SessionKeys::SCOPE) !== SessionKeys::SCOPE_PLATFORM) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();

            throw new AuthenticationException;
        }

        if (! $user->active || ! $user->isPlatformStaff()) {
            throw ApiException::forbidden();
        }

        return $this->context->runAsPlatform(fn () => $next($request));
    }
}
