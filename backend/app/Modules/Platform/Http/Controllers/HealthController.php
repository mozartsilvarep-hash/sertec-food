<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Modules\Platform\Ops\HealthCheck;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/v1/internal/health — rede interna (bloqueada na borda), para o
 * monitor/healthcheck do Docker. Monitores externos usam /up.
 */
class HealthController
{
    public function __invoke(HealthCheck $health): JsonResponse
    {
        $result = $health->run();

        return new JsonResponse($result, $result['ok'] ? 200 : 503);
    }
}
