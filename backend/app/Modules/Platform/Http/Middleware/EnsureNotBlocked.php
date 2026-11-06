<?php

namespace App\Modules\Platform\Http\Middleware;

use App\Http\Errors\ApiException;
use App\Modules\Platform\Features\FeatureGate;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restaurante bloqueado por mensalidade atrasada (D101): no painel só
 * funcionam a sessão, a conta e a tela de pagar. Uso: ->middleware('not-blocked').
 */
class EnsureNotBlocked
{
    /** Rotas liberadas mesmo bloqueado (nomes completos ou prefixos com "*"). */
    private const ALLOWED = ['merchant.me', 'merchant.me.password', 'merchant.auth.logout', 'merchant.billing.*'];

    public function handle(Request $request, Closure $next): Response
    {
        $name = (string) $request->route()?->getName();
        foreach (self::ALLOWED as $allowed) {
            if ($name === $allowed || (str_ends_with($allowed, '*') && str_starts_with($name, rtrim($allowed, '*')))) {
                return $next($request);
            }
        }
        if (app(FeatureGate::class)->blocked()) {
            throw new ApiException(403, 'account_blocked', 'Sistema bloqueado por mensalidade em atraso. Pague em Assinatura para liberar na hora.');
        }

        return $next($request);
    }
}
