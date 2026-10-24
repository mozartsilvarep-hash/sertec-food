<?php

namespace App\Modules\Tenancy\Jobs;

use App\Modules\Tenancy\Jobs\Middleware\TenantAware;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Base para jobs de negócio. Captura o tenant_id do contexto no momento do
 * dispatch e o restaura no worker (middleware TenantAware), independentemente
 * do que o worker processou antes.
 */
abstract class TenantJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tenantId;

    /**
     * Subclasses com construtor próprio DEVEM chamar parent::__construct().
     */
    public function __construct()
    {
        $this->tenantId = app(TenantContext::class)->id();
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new TenantAware];
    }
}
