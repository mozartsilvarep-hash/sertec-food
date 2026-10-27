<?php

namespace Database\Seeders;

use App\Modules\Platform\Features\Feature;
use App\Modules\Platform\Models\Plan;
use Illuminate\Database\Seeder;

/**
 * Planos iniciais. Só CRIA os que faltam: preço, nome e recursos são
 * editados no painel SerTec e nunca sobrescritos por um novo seed (D81).
 * Recursos novos chegam aos planos existentes por migration.
 */
class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            'cardapio' => [
                'name' => 'Cardápio Digital',
                'features' => [Feature::THERMAL_PRINT, Feature::CASHBOOK, Feature::LOYALTY],
            ],
            'site-cardapio' => [
                'name' => 'Site Profissional + Cardápio',
                'features' => [Feature::SITE_MODE, Feature::CUSTOM_DOMAIN, Feature::THERMAL_PRINT, Feature::COUPONS, Feature::REPORTS, Feature::CASHBOOK, Feature::LOYALTY],
            ],
        ];

        foreach ($plans as $slug => $plan) {
            $features = array_fill_keys(Feature::all(), false);
            foreach ($plan['features'] as $feature) {
                $features[$feature] = true;
            }

            Plan::query()->firstOrCreate(['slug' => $slug], [
                'name' => $plan['name'],
                'monthly_price' => '0.00',
                'prices_json' => ['1' => null, '3' => null, '6' => null, '12' => null],
                'features_json' => $features,
                'active' => true,
            ]);
        }
    }
}
