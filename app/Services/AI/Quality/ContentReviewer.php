<?php

namespace App\Services\AI\Quality;

use App\Models\Product;

/**
 * Produces the SEO and quality feedback shown in the preview. Makes no AI calls.
 */
final class ContentReviewer
{
    public function __construct(
        private readonly SeoAnalyzer $seo,
        private readonly ContentQualityChecker $quality,
    ) {}

    /**
     * @param  array<string, mixed>  $fields  keywords as a list or a comma-separated string
     * @return array{seo: array<string, mixed>, quality: list<array<string, mixed>>}
     */
    public function review(Product $product, array $fields): array
    {
        $fields['keywords'] = $this->keywordList($fields['keywords'] ?? null);

        return [
            'seo' => $this->seo->analyze($fields, $product->original_name),
            'quality' => $this->quality->check($product, $fields),
        ];
    }

    /**
     * @return list<string>
     */
    private function keywordList(mixed $keywords): array
    {
        if (is_string($keywords)) {
            $keywords = explode(',', $keywords);
        }

        if (! is_array($keywords)) {
            return [];
        }

        return array_values(array_filter(
            array_map(fn ($keyword) => is_string($keyword) ? trim($keyword) : '', $keywords),
            fn (string $keyword) => $keyword !== '',
        ));
    }
}
