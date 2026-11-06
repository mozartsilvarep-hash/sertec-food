<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Http\Errors\ApiException;
use App\Modules\Identity\Enums\PlatformRole;
use App\Modules\Identity\Models\User;
use App\Modules\Platform\Audit\AuditLogger;
use App\Modules\Platform\Models\BillingModule;
use App\Modules\Platform\Models\Plan;
use App\Modules\Platform\Models\Subscription;
use App\Modules\Platform\Modules\ModuleKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Preço dos módulos (painel SerTec): mensal e anual (e, se quiser,
 * trimestral/semestral). Só superadmin altera.
 */
class AdminModuleController
{
    public function index(): JsonResponse
    {
        $counts = array_fill_keys(ModuleKey::values(), 0);
        foreach (Subscription::query()->whereIn('status', [Subscription::STATUS_ACTIVE, Subscription::STATUS_PAST_DUE, Subscription::STATUS_TRIALING])->get(['modules_json']) as $s) {
            foreach ($s->modules() as $m) {
                $counts[$m->value]++;
            }
        }

        return new JsonResponse([
            'data' => array_values(array_map(fn (BillingModule $m) => self::present($m, $counts[$m->key] ?? 0), BillingModule::catalog())),
            'meta' => ['periods' => collect(Plan::PERIODS)->map(fn ($label, $months) => ['months' => $months, 'label' => $label])->values()],
        ]);
    }

    public function update(Request $request, BillingModule $module, AuditLogger $audit): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User || $user->platform_role !== PlatformRole::Superadmin) {
            throw ApiException::forbidden();
        }
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'min:2', 'max:80'],
            'description' => ['sometimes', 'nullable', 'string', 'max:300'],
            'active' => ['sometimes', 'boolean'],
            'prices' => ['sometimes', 'array'],
            'prices.*' => ['nullable', 'regex:/^\d{1,7}([.,]\d{1,2})?$/'],
        ], ['prices.*.regex' => 'Preço inválido. Use o formato 99,90.'], ['name' => 'nome', 'description' => 'descrição']);

        $out = array_intersect_key($data, array_flip(['name', 'description', 'active']));
        if (array_key_exists('prices', $data)) {
            $prices = [];
            foreach (array_keys(Plan::PERIODS) as $months) {
                $value = $data['prices'][(string) $months] ?? $data['prices'][$months] ?? null;
                $prices[(string) $months] = $value === null || $value === '' ? null : number_format((float) str_replace(',', '.', (string) $value), 2, '.', '');
            }
            $out['prices_json'] = $prices;
        }
        $module->fill($out)->save();
        $audit->record('billing.module_updated', null, ['module' => $module->key, 'fields' => array_keys($out)]);

        return new JsonResponse(['data' => self::present($module, 0)]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(BillingModule $m, int $tenants): array
    {
        $prices = $m->prices();

        return [
            'key' => $m->key,
            'name' => $m->name,
            'description' => $m->description,
            'active' => $m->active,
            'requires' => $m->moduleKey()->requires()?->value,
            'prices' => array_combine(array_map('strval', array_keys($prices)), array_values($prices)),
            'tenants' => $tenants,
        ];
    }
}
