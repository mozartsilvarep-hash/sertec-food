<?php

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/v1/storefront — versão mínima da Sprint 0 (identidade pública do
 * estabelecimento). Catálogo e marca completos entram na Sprint 1.
 */
class StorefrontController
{
    public function __invoke(TenantContext $context): JsonResponse
    {
        $tenant = $context->get();

        return new JsonResponse(['data' => [
            'name' => $tenant->name,
            'slug' => $tenant->slug,
            'timezone' => $tenant->timezone,
        ]]);
    }
}
