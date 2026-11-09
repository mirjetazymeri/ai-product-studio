<?php

namespace Database\Factories;

use App\Enums\ProductStatus;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'original_name' => ucfirst(fake()->words(3, true)),
            'sku' => fake()->unique()->bothify('SKU-####-??'),
            'category' => fake()->word(),
            'material' => fake()->word(),
            'details' => fake()->sentence(),
            'status' => ProductStatus::Draft,
        ];
    }

    public function published(): static
    {
        return $this->state(['status' => ProductStatus::Published]);
    }
}
