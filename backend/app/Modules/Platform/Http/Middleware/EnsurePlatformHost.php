<?php

namespace App\Modules\Platform\Http\Middleware;

use App\Modules\Tenancy\Models\TenantDomain;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Rotas /api/v1/admin/* só existem no host do painel SerTec.
 * Em host de tenant respondem 404 (não revelam a existência do painel).
 */
class EnsurePlatformHost
{
    public function handle(Request $request, Closure $next): Response
    {
        $host = TenantDomain::normalize($request->getHost());

        if ($host !== TenantDomain::normalize((string) config('tenancy.admin_host'))) {
            throw new NotFoundHttpException;
        }

        return $next($request);
    }
}
