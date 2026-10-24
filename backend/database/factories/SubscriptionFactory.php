<?php

namespace Database\Factories;

use App\Modules\Platform\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subscription>
 */
class SubscriptionFactory extends Factory
{
    protected $model = Subscription::class;

    public function definition(): array
    {
        return [
            'status' => Subscription::STATUS_ACTIVE,
            'period_end' => null,
        ];
    }
}
