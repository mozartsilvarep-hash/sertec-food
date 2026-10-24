<?php

namespace Database\Factories;

use App\Modules\Catalog\Models\Menu;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Menu> */
class MenuFactory extends Factory
{
    protected $model = Menu::class;

    public function definition(): array
    {
        return ['name' => 'Cardápio', 'branch_id' => null, 'sort_order' => 0, 'active' => true];
    }
}
