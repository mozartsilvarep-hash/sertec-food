<?php

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Models\Branch;
use App\Modules\Tenancy\Schedule\OpeningSchedule;
use App\Modules\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/v1/storefront — identidade pública do estabelecimento e situação
 * (aberto/fechado) de cada unidade ativa.
 */
class StorefrontController
{
    public function __invoke(TenantContext $context): JsonResponse
    {
        $tenant = $context->get();
        $now = CarbonImmutable::now();

        $branches = Branch::query()->where('active', true)->orderBy('id')->get()
            ->map(function (Branch $branch) use ($tenant, $now) {
                $schedule = OpeningSchedule::forBranch($branch, $tenant->timezone);

                return [
                    'id' => $branch->public_id,
                    'name' => $branch->name,
                    'phone' => $branch->phone,
                    'address' => $branch->address_json,
                    'has_hours' => $schedule->hasAnyHours(),
                    ...$schedule->status($now),
                ];
            })->values();

        return new JsonResponse(['data' => [
            'name' => $tenant->name,
            'slug' => $tenant->slug,
            'timezone' => $tenant->timezone,
            'description' => $tenant->description,
            'brand_color' => $tenant->brand_color,
            'branches' => $branches,
        ]]);
    }
}
