<?php

namespace Database\Factories;

use App\Modules\Catalog\Models\OptionGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<OptionGroup> */
class OptionGroupFactory extends Factory
{
    protected $model = OptionGroup::class;

    public function definition(): array
    {
        return ['name' => 'Adicionais', 'type' => 'addon', 'min_select' => 0, 'max_select' => 5, 'free_quantity' => 0, 'active' => true];
    }
}
