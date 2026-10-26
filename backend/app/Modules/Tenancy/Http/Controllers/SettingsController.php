<?php

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Platform\Audit\AuditLogger;
use App\Modules\Tenancy\Support\BrandColor;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Configurações visíveis do estabelecimento. Nome e slug são alterados
 * apenas pela SerTec (painel de plataforma).
 */
class SettingsController
{
    public function show(TenantContext $context): JsonResponse
    {
        return new JsonResponse(['data' => $this->present($context)]);
    }

    public function update(Request $request, TenantContext $context, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'brand_color' => ['sometimes', 'nullable', 'regex:/^#[0-9a-fA-F]{6}$/', function (string $attribute, mixed $value, \Closure $fail) {
                if (is_string($value) && ! BrandColor::isAccessible($value)) {
                    $fail('Cor clara demais: o texto branco ficaria ilegível. Escolha um tom mais escuro.');
                }
            }],
        ]);

        if (isset($data['brand_color'])) {
            $data['brand_color'] = strtolower($data['brand_color']);
        }

        $tenant = $context->get();
        $tenant->fill($data)->save();
        Cache::forget("storefront-meta:{$tenant->id}");
        $audit->record('tenant.settings_updated', $tenant, ['fields' => array_keys($data)]);

        return new JsonResponse(['data' => $this->present($context)]);
    }

    /** @return array<string, mixed> */
    private function present(TenantContext $context): array
    {
        $tenant = $context->get();

        return [
            'name' => $tenant->name,
            'slug' => $tenant->slug,
            'timezone' => $tenant->timezone,
            'description' => $tenant->description,
            'brand_color' => $tenant->brand_color,
        ];
    }
}
