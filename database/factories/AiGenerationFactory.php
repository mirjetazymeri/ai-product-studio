<?php

namespace Database\Factories;

use App\Enums\AiError;
use App\Enums\AiGenerationStatus;
use App\Enums\ContentLanguage;
use App\Models\AiGeneration;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiGeneration>
 */
class AiGenerationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'product_id' => Product::factory(),
            'product_name' => ucfirst(fake()->words(3, true)),
            'provider' => 'gemini',
            'model' => 'test-gemini-model',
            'language' => ContentLanguage::English,
            'status' => AiGenerationStatus::Succeeded,
            'input_tokens' => 100,
            'output_tokens' => 50,
            'total_tokens' => 150,
            'generated_content' => [
                'title' => 'Generated title',
                'short_description' => 'Generated short description.',
                'long_description' => 'Generated long description.',
                'meta_title' => 'Generated meta title',
                'meta_description' => 'Generated meta description.',
                'slug' => 'generated-title',
                'keywords' => ['generated', 'keyword'],
            ],
        ];
    }

    public function failed(AiError $error = AiError::Timeout): static
    {
        return $this->state([
            'status' => AiGenerationStatus::Failed,
            'error_code' => $error->value,
            'generated_content' => null,
            'input_tokens' => null,
            'output_tokens' => null,
            'total_tokens' => null,
        ]);
    }
}
