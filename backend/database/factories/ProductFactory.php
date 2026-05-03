<?php

namespace Database\Factories;

use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProductFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->words(3, true),
            'sku' => strtoupper(fake()->unique()->bothify('SKU-####')),
            'description' => fake()->sentence(),
            'price' => fake()->numberBetween(25000, 750000),
            'stock' => fake()->numberBetween(5, 100),
            'category_id' => Category::factory(),
        ];
    }
}
