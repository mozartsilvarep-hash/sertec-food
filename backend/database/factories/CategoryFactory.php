<?php

namespace Database\Factories;

use App\Modules\Catalog\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Category> */
class CategoryFactory extends Factory
{
    protected $model = Category::class;

    public function definition(): array
    {
        return ['name' => ucfirst(fake()->unique()->word()), 'sort_order' => 0, 'active' => true];
    }
}
