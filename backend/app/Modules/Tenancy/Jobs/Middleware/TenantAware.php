<?php

namespace App\Modules\Tenancy\Jobs\Middleware;

use App\Modules\Tenancy\Exceptions\MissingTenantContext;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\TenantContext;
use Closure;

class TenantAware
{
    public function handle(object $job, Closure $next): mixed
    {
        $tenantId = property_exists($job, 'tenantId') ? $job->tenantId : null;

        if (! is_int($tenantId)) {
            throw new MissingTenantContext('Queued job has no tenant_id.');
        }

        $tenant = Tenant::query()->find($tenantId)
            ?? throw new MissingTenantContext("Tenant {$tenantId} not found for queued job.");

        return app(TenantContext::class)->run($tenant, fn () => $next($job));
    }
}
