<?php

namespace Database\Factories;

use App\Modules\Catalog\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Product> */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        return ['name' => ucfirst(fake()->unique()->words(2, true)), 'description' => fake()->sentence(), 'active' => true];
    }
}
