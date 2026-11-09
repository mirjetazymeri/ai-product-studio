<?php

namespace Database\Factories;

use App\Enums\ContentLanguage;
use App\Enums\ContentSource;
use App\Models\Product;
use App\Models\ProductContent;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ProductContent>
 */
class ProductContentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = ucfirst(fake()->words(4, true));

        return [
            'product_id' => Product::factory(),
            'language' => ContentLanguage::English,
            'title' => $title,
            'short_description' => fake()->sentence(),
            'long_description' => fake()->paragraph(),
            'meta_title' => $title,
            'meta_description' => fake()->sentence(),
            'slug' => Str::slug($title),
            'keywords' => fake()->words(3),
            'source' => ContentSource::Manual,
        ];
    }

    public function language(ContentLanguage $language): static
    {
        return $this->state(['language' => $language]);
    }

    public function ai(): static
    {
        return $this->state(['source' => ContentSource::Ai]);
    }
}
