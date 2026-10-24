<?php

namespace App\Modules\Tenancy\Http\Middleware;

use App\Http\Errors\ApiException;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantDomain;
use App\Modules\Tenancy\Scopes\TenantScope;
use App\Modules\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Define o tenant da requisição EXCLUSIVAMENTE pelo Host verificado.
 * Header X-Tenant-Id, query ou body com tenant_id são ignorados.
 *
 * - Host desconhecido ou domínio próprio não verificado: 404.
 * - Tenant não ativo (pending/suspended/cancelled): 403 tenant_unavailable.
 */
class ResolveTenantFromHost
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $host = TenantDomain::normalize($request->getHost());

        if ($host === TenantDomain::normalize((string) config('tenancy.admin_host'))) {
            throw new NotFoundHttpException;
        }

        // Única consulta sem escopo do fluxo de requisição: ainda não há tenant.
        $domain = TenantDomain::withoutGlobalScope(TenantScope::class)
            ->where('hostname', $host)
            ->whereNotNull('verified_at')
            ->first();

        if ($domain === null) {
            throw new NotFoundHttpException;
        }

        $tenant = Tenant::query()->find($domain->tenant_id);

        if ($tenant === null) {
            throw new NotFoundHttpException;
        }

        if (! $tenant->status->isOperational()) {
            throw ApiException::tenantUnavailable();
        }

        $request->attributes->set('tenant', $tenant);

        return $this->context->run($tenant, fn () => $next($request));
    }
}
