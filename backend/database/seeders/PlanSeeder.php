<?php

namespace Database\Seeders;

use App\Modules\Platform\Features\Feature;
use App\Modules\Platform\Models\Plan;
use Illuminate\Database\Seeder;

/**
 * Planos iniciais. PREÇOS em 0.00 de propósito: valores comerciais são
 * decisão da SerTec e devem ser definidos no painel/seed antes do piloto.
 */
class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            'cardapio' => [
                'name' => 'Cardápio Digital',
                'features' => [Feature::THERMAL_PRINT, Feature::CASHBOOK],
            ],
            'site-cardapio' => [
                'name' => 'Site Profissional + Cardápio',
                'features' => [Feature::SITE_MODE, Feature::CUSTOM_DOMAIN, Feature::THERMAL_PRINT, Feature::COUPONS, Feature::REPORTS, Feature::CASHBOOK],
            ],
        ];

        foreach ($plans as $slug => $plan) {
            $features = array_fill_keys(Feature::all(), false);
            foreach ($plan['features'] as $feature) {
                $features[$feature] = true;
            }

            Plan::query()->updateOrCreate(['slug' => $slug], [
                'name' => $plan['name'],
                'monthly_price' => '0.00',
                'features_json' => $features,
                'active' => true,
            ]);
        }
    }
}
