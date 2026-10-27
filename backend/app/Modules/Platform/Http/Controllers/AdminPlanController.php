<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Http\Errors\ApiException;
use App\Modules\Identity\Enums\PlatformRole;
use App\Modules\Identity\Models\User;
use App\Modules\Platform\Audit\AuditLogger;
use App\Modules\Platform\Features\Feature;
use App\Modules\Platform\Models\Plan;
use App\Modules\Platform\Models\Subscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Planos da SerTec: nome, preço por período e recursos. Mudança de preço
 * vale para as próximas faturas (as já geradas guardam o valor).
 */
class AdminPlanController
{
    /** Nome dos recursos no painel. */
    public const FEATURE_LABELS = [
        Feature::SITE_MODE => 'Site profissional',
        Feature::CUSTOM_DOMAIN => 'Domínio próprio',
        Feature::MULTI_BRANCH => 'Várias unidades',
        Feature::COUPONS => 'Cupons e promoções',
        Feature::REPORTS => 'Relatórios',
        Feature::THERMAL_PRINT => 'Comanda impressa',
        Feature::WHATSAPP_NOTIFICATIONS => 'Avisos por WhatsApp',
        Feature::CASHBOOK => 'Caixa (mini PDV)',
        Feature::LOYALTY => 'Cartão fidelidade',
    ];

    public function index(): JsonResponse
    {
        $counts = Subscription::query()->whereIn('status', [Subscription::STATUS_ACTIVE, Subscription::STATUS_PAST_DUE, Subscription::STATUS_TRIALING])
            ->selectRaw('plan_id, COUNT(*) AS c')->groupBy('plan_id')->toBase()->pluck('c', 'plan_id');

        return new JsonResponse([
            'data' => Plan::query()->orderBy('id')->get()->map(fn (Plan $p) => self::present($p, (int) ($counts[$p->id] ?? 0)))->values(),
            'meta' => [
                'periods' => collect(Plan::PERIODS)->map(fn ($label, $months) => ['months' => $months, 'label' => $label])->values(),
                'features' => collect(Feature::all())->map(fn ($f) => ['key' => $f, 'label' => self::FEATURE_LABELS[$f] ?? $f])->values(),
            ],
        ]);
    }

    public function store(Request $request, AuditLogger $audit): JsonResponse
    {
        $this->requireSuperadmin($request);
        $data = $this->validated($request, null);
        $plan = Plan::query()->create($data);
        $audit->record('plan.created', null, ['plan' => $plan->slug]);

        return new JsonResponse(['data' => self::present($plan, 0)], 201);
    }

    public function update(Request $request, Plan $plan, AuditLogger $audit): JsonResponse
    {
        $this->requireSuperadmin($request);
        $data = $this->validated($request, $plan);
        $plan->fill($data)->save();
        $audit->record('plan.updated', null, ['plan' => $plan->slug, 'fields' => array_keys($data)]);

        return new JsonResponse(['data' => self::present($plan, 0)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Plan $plan): array
    {
        $data = $request->validate([
            'slug' => [$plan ? 'prohibited' : 'required', 'string', 'max:60', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/', Rule::unique('plans', 'slug')],
            'name' => [$plan ? 'sometimes' : 'required', 'string', 'min:2', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'active' => ['sometimes', 'boolean'],
            'prices' => ['sometimes', 'array'],
            'prices.*' => ['nullable', 'regex:/^\d{1,7}([.,]\d{1,2})?$/'],
            'features' => ['sometimes', 'array'],
            'features.*' => ['boolean'],
        ], [
            'slug.regex' => 'Use letras minúsculas, números e hífen (ex.: plano-completo).',
            'prices.*.regex' => 'Preço inválido. Use o formato 99,90.',
        ], ['slug' => 'identificador', 'name' => 'nome', 'description' => 'descrição']);

        $out = array_intersect_key($data, array_flip(['slug', 'name', 'description', 'active']));
        if (array_key_exists('prices', $data)) {
            $prices = [];
            foreach (array_keys(Plan::PERIODS) as $months) {
                $value = $data['prices'][(string) $months] ?? $data['prices'][$months] ?? null;
                $prices[(string) $months] = $value === null || $value === '' ? null : number_format((float) str_replace(',', '.', (string) $value), 2, '.', '');
            }
            $out['prices_json'] = $prices;
            $out['monthly_price'] = $prices['1'] ?? '0.00';
        } elseif ($plan === null) {
            $out['prices_json'] = array_fill_keys(array_map('strval', array_keys(Plan::PERIODS)), null);
            $out['monthly_price'] = '0.00';
        }
        if (array_key_exists('features', $data) || $plan === null) {
            $current = $plan->features_json ?? [];
            foreach (Feature::all() as $f) {
                $out['features_json'][$f] = (bool) ($data['features'][$f] ?? $current[$f] ?? false);
            }
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(Plan $p, int $tenants): array
    {
        return [
            'slug' => $p->slug,
            'name' => $p->name,
            'description' => $p->description,
            'active' => $p->active,
            'monthly_price' => $p->monthly_price,
            'prices' => array_map(fn ($v) => $v, array_combine(array_map('strval', array_keys($p->prices())), array_values($p->prices()))),
            'features' => array_map(fn ($v) => $v === true, $p->features_json),
            'tenants' => $tenants,
        ];
    }

    private function requireSuperadmin(Request $request): void
    {
        $user = $request->user();
        if (! $user instanceof User || $user->platform_role !== PlatformRole::Superadmin) {
            throw ApiException::forbidden();
        }
    }
}
