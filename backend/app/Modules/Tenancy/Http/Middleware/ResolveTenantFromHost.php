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
        $tenant = self::lookup($request->getHost()) ?? throw new NotFoundHttpException;

        if (! $tenant->status->isOperational()) {
            throw ApiException::tenantUnavailable();
        }

        $request->attributes->set('tenant', $tenant);

        return $this->context->run($tenant, fn () => $next($request));
    }

    /**
     * Tenant dono do host (domínio verificado), em qualquer situação; null se
     * o host for desconhecido ou o do painel SerTec.
     */
    public static function lookup(string $rawHost): ?Tenant
    {
        $host = TenantDomain::normalize($rawHost);

        if ($host === TenantDomain::normalize((string) config('tenancy.admin_host'))) {
            return null;
        }

        // Única consulta sem escopo do fluxo de requisição: ainda não há tenant.
        $domain = TenantDomain::withoutGlobalScope(TenantScope::class)
            ->where('hostname', $host)
            ->whereNotNull('verified_at')
            ->first();

        return $domain === null ? null : Tenant::query()->find($domain->tenant_id);
    }
}
