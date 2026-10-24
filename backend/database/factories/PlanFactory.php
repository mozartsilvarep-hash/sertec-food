<?php

namespace Database\Factories;

use App\Modules\Platform\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    protected $model = Plan::class;

    public function definition(): array
    {
        return [
            'slug' => 'plano-'.fake()->unique()->lexify('?????'),
            'name' => 'Plano '.fake()->word(),
            'monthly_price' => '0.00',
            'features_json' => [],
            'active' => true,
        ];
    }

    /**
     * @param  list<string>  $features
     */
    public function withFeatures(array $features): static
    {
        return $this->state(fn () => ['features_json' => array_fill_keys($features, true)]);
    }
}
