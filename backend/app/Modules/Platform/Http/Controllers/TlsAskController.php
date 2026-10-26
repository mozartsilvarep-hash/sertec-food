<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Modules\Tenancy\Models\TenantDomain;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * GET /internal/tls-ask?domain=… — consultado pelo Caddy (on-demand TLS)
 * antes de emitir um certificado. 200 só para o host do painel SerTec e
 * domínios cadastrados e verificados; impede que qualquer nome apontado para
 * o servidor esgote o limite do Let's Encrypt. Rota bloqueada na borda.
 */
class TlsAskController
{
    public function __invoke(Request $request, TenantContext $context): Response
    {
        $domain = TenantDomain::normalize((string) $request->query('domain', ''));

        $allowed = $domain !== '' && (
            $domain === config('tenancy.admin_host')
            || $context->runAsPlatform(fn () => TenantDomain::query()->where('hostname', $domain)->whereNotNull('verified_at')->exists())
        );

        return new Response('', $allowed ? 200 : 404);
    }
}
