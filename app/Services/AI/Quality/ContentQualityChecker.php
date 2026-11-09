<?php

namespace App\Services\AI\Quality;

use App\Models\Product;

/**
 * Compares content against the product's own facts, without any AI call.
 *
 * "error" means something is definitely wrong. "review" means a fixed pattern found
 * something worth a human look, which may still be correct, such as a translated term.
 */
final class ContentQualityChecker
{
    private const REQUIRED_FIELDS = [
        'title' => 'Title',
        'short_description' => 'Short description',
        'long_description' => 'Long description',
        'meta_title' => 'Meta title',
        'meta_description' => 'Meta description',
        'slug' => 'URL slug',
        'keywords' => 'Keywords',
    ];

    private const UNUSUALLY_SHORT = ['short_description' => 20, 'long_description' => 40];

    public function __construct(private readonly ProductFactExtractor $facts) {}

    /**
     * @param  array<string, mixed>  $fields  keywords as a list of strings
     * @return list<array{code: string, level: string, field: ?string, message: string}>
     */
    public function check(Product $product, array $fields): array
    {
        $source = implode("\n", array_filter([$product->original_name, $product->category, $product->material, $product->details], filled(...)));
        $generated = $this->generatedText($fields);

        return [
            ...$this->structureIssues($fields),
            ...$this->measurementIssues($source, $generated),
            ...$this->materialIssues($product, $source, $generated),
            ...$this->compatibilityIssues($source, $generated),
            ...$this->claimIssues($source, $generated),
        ];
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function generatedText(array $fields): string
    {
        $text = array_map(
            fn (string $field) => is_string($fields[$field] ?? null) ? $fields[$field] : '',
            ['title', 'short_description', 'long_description', 'meta_title', 'meta_description'],
        );

        return implode("\n", [...$text, implode(', ', $fields['keywords'] ?? [])]);
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return list<array<string, mixed>>
     */
    private function structureIssues(array $fields): array
    {
        $issues = [];

        foreach (self::REQUIRED_FIELDS as $field => $label) {
            if (blank($fields[$field] ?? null)) {
                $issues[] = $this->issue('missing_field', 'error', $field, "{$label} is empty.");
            }
        }

        foreach (self::UNUSUALLY_SHORT as $field => $minimum) {
            $value = $fields[$field] ?? '';

            if (is_string($value) && filled($value) && mb_strlen(trim($value)) < $minimum) {
                $issues[] = $this->issue('unusually_short', 'review', $field, self::REQUIRED_FIELDS[$field].' is unusually short.');
            }
        }

        $slug = $fields['slug'] ?? null;

        if (is_string($slug) && filled($slug) && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) !== 1) {
            $issues[] = $this->issue('invalid_slug', 'review', 'slug', 'The URL slug has invalid characters and will be cleaned up when saved.');
        }

        $keywords = array_map(fn ($keyword) => mb_strtolower(trim((string) $keyword)), $fields['keywords'] ?? []);
        $duplicates = array_keys(array_filter(array_count_values($keywords), fn (int $count) => $count > 1));

        if ($duplicates !== []) {
            $issues[] = $this->issue('duplicate_keywords', 'review', 'keywords', 'Repeated keywords: '.implode(', ', $duplicates).'.');
        }

        return $issues;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function measurementIssues(string $source, string $generated): array
    {
        $issues = [];
        $sourceMeasurements = $this->facts->measurements($source);
        $generatedMeasurements = $this->facts->measurements($generated);

        foreach (array_diff($generatedMeasurements, $sourceMeasurements) as $measurement) {
            $issues[] = $this->issue('changed_measurement', 'review', null, "Mentions “{$measurement}”, which isn't in the product information. Please verify it.");
        }

        foreach (array_diff($sourceMeasurements, $generatedMeasurements) as $measurement) {
            $issues[] = $this->issue('missing_measurement', 'review', null, "The product information says “{$measurement}”, but the content doesn't mention it.");
        }

        foreach (array_diff($this->facts->bareNumbers($generated), $this->facts->allNumbers($source)) as $number) {
            $issues[] = $this->issue('unsupported_number', 'review', null, "Mentions the number “{$number}”, which isn't in the product information. Please verify it.");
        }

        return $issues;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function materialIssues(Product $product, string $source, string $generated): array
    {
        $issues = [];
        $sourceMaterials = $this->facts->materials($source);
        $generatedMaterials = $this->facts->materials($generated);

        foreach (array_diff($generatedMaterials, $sourceMaterials) as $material) {
            $family = ProductFactExtractor::MATERIAL_FAMILIES[$material] ?? [];

            if (array_intersect($family, $sourceMaterials) === []) {
                $issues[] = $this->issue('changed_material', 'review', null, "Mentions {$material}, which isn't in the product information. Please verify it.");
            }
        }

        foreach (array_diff($sourceMaterials, $generatedMaterials) as $material) {
            $issues[] = $this->issue('missing_material', 'review', null, "The product information mentions {$material}, but the content doesn't appear to.");
        }

        // A material outside the known list can only be checked word for word.
        if (filled($product->material) && $this->facts->materials($product->material) === []
            && mb_stripos($generated, trim($product->material)) === false) {
            $issues[] = $this->issue('missing_material', 'review', null, "The product's material, “{$product->material}”, doesn't appear in the content. It may be translated, so please check it.");
        }

        return $issues;
    }

    /**
     * A general mention is fine when the facts are more specific, so PlayStation is
     * supported by PS5, but a specific model is not supported by a general mention.
     *
     * @return list<array<string, mixed>>
     */
    private function compatibilityIssues(string $source, string $generated): array
    {
        $issues = [];
        $sourceTokens = $this->facts->compatibility($source);
        $generatedTokens = $this->facts->compatibility($generated);

        foreach ($generatedTokens as $token) {
            if (! $this->anyStartsWith($sourceTokens, $token)) {
                $issues[] = $this->issue('changed_compatibility', 'review', null, "Mentions “{$token}”, which isn't in the product information. Please verify the compatibility.");
            }
        }

        foreach ($sourceTokens as $token) {
            if (! $this->anyStartsWith($generatedTokens, $token)) {
                $issues[] = $this->issue('missing_compatibility', 'review', null, "The product information mentions “{$token}”, but the content doesn't.");
            }
        }

        return $issues;
    }

    /**
     * @param  list<string>  $candidates
     */
    private function anyStartsWith(array $candidates, string $prefix): bool
    {
        foreach ($candidates as $candidate) {
            if (str_starts_with($candidate, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function claimIssues(string $source, string $generated): array
    {
        return array_values(array_map(
            fn (string $claim) => $this->issue('unsupported_claim', 'review', null, "May include {$claim} that isn't in the product information. Please verify it."),
            array_diff($this->facts->claims($generated), $this->facts->claims($source)),
        ));
    }

    /**
     * @return array{code: string, level: string, field: ?string, message: string}
     */
    private function issue(string $code, string $level, ?string $field, string $message): array
    {
        return ['code' => $code, 'level' => $level, 'field' => $field, 'message' => $message];
    }
}
