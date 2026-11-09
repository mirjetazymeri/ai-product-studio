<?php

namespace Tests\Unit\AI;

use App\Models\Product;
use App\Services\AI\Quality\ContentQualityChecker;
use App\Services\AI\Quality\ProductFactExtractor;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ContentQualityCheckerTest extends TestCase
{
    private function product(array $attributes = []): Product
    {
        return new Product(array_replace([
            'original_name' => 'Controller Stand',
            'category' => 'Gaming Accessories',
            'material' => 'PLA',
            'details' => 'Holds PS5 controllers. Size 10 x 20 cm, height 15 cm.',
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function faithfulFields(array $overrides = []): array
    {
        return array_replace([
            'title' => 'PS5 Controller Stand',
            'short_description' => 'A practical PLA stand that holds PS5 controllers.',
            'long_description' => 'This PLA stand measures 10 x 20 cm and is 15 cm tall, keeping PS5 controllers organized.',
            'meta_title' => 'PLA Stand for PS5 Controllers',
            'meta_description' => 'A PLA stand for PS5 controllers.',
            'slug' => 'ps5-controller-stand',
            'keywords' => ['ps5 stand', 'controller holder'],
        ], $overrides);
    }

    /**
     * @return list<string>
     */
    private function codes(array $overrides = [], array $attributes = []): array
    {
        $issues = app(ContentQualityChecker::class)->check($this->product($attributes), $this->faithfulFields($overrides));

        return array_column($issues, 'code');
    }

    public function test_faithful_content_raises_no_issues(): void
    {
        $this->assertSame([], $this->codes());
    }

    public function test_missing_required_fields_are_errors(): void
    {
        $issues = app(ContentQualityChecker::class)->check($this->product(), $this->faithfulFields(['meta_title' => '', 'keywords' => []]));
        $missing = collect($issues)->where('code', 'missing_field');

        $this->assertSame(['meta_title', 'keywords'], $missing->pluck('field')->all());
        $this->assertSame(['error'], $missing->pluck('level')->unique()->values()->all());
    }

    public function test_unusually_short_text_is_flagged(): void
    {
        $this->assertContains('unusually_short', $this->codes(['short_description' => 'Too short.']));
    }

    public function test_a_changed_measurement_is_flagged_for_review(): void
    {
        $issues = app(ContentQualityChecker::class)->check($this->product(), $this->faithfulFields([
            'long_description' => 'This PLA stand measures 10 x 20 cm and is 18 cm tall, keeping PS5 controllers organized.',
        ]));

        $changed = collect($issues)->firstWhere('code', 'changed_measurement');

        $this->assertSame('review', $changed['level']);
        $this->assertStringContainsString('18 cm', $changed['message']);
        $this->assertStringContainsString('verify', $changed['message']);
    }

    public function test_a_dropped_measurement_is_flagged(): void
    {
        $this->assertContains('missing_measurement', $this->codes(['long_description' => 'This PLA stand keeps PS5 controllers organized on any desk.']));
    }

    public function test_an_invented_number_is_flagged(): void
    {
        $this->assertContains('unsupported_number', $this->codes(['short_description' => 'Holds 4 PS5 controllers in PLA at once.']));
    }

    public function test_a_changed_material_is_flagged(): void
    {
        $this->assertContains('changed_material', $this->codes(['short_description' => 'A practical PETG stand that holds PS5 controllers and PLA.']));
    }

    public function test_a_dropped_material_is_flagged(): void
    {
        $codes = $this->codes([
            'short_description' => 'A practical stand that holds PS5 controllers.',
            'long_description' => 'This stand measures 10 x 20 cm and is 15 cm tall, keeping PS5 controllers organized.',
            'meta_title' => 'Stand for PS5 Controllers',
            'meta_description' => 'A stand for PS5 controllers.',
        ]);

        $this->assertContains('missing_material', $codes);
    }

    public function test_a_general_material_word_is_allowed_for_a_specific_material(): void
    {
        $this->assertNotContains('changed_material', $this->codes(['short_description' => 'A durable plastic stand in PLA for PS5 controllers.']));
    }

    public function test_an_unknown_material_is_checked_word_for_word(): void
    {
        $this->assertContains('missing_material', $this->codes(attributes: ['material' => 'Terracotta']));
    }

    public function test_added_compatibility_is_flagged(): void
    {
        $this->assertContains('changed_compatibility', $this->codes(['short_description' => 'A PLA stand for PS5 and Xbox controllers.']));
    }

    public function test_dropped_compatibility_is_flagged(): void
    {
        $codes = $this->codes([
            'title' => 'Controller Stand',
            'short_description' => 'A practical PLA stand that holds controllers.',
            'long_description' => 'This PLA stand measures 10 x 20 cm and is 15 cm tall.',
            'meta_title' => 'PLA Controller Stand',
            'meta_description' => 'A PLA stand for controllers.',
            'slug' => 'controller-stand',
            'keywords' => ['controller stand'],
        ]);

        $this->assertContains('missing_compatibility', $codes);
    }

    public function test_playstation_spelled_out_matches_ps5(): void
    {
        $this->assertNotContains('changed_compatibility', $this->codes(['short_description' => 'A PLA stand for PlayStation 5 controllers.']));
    }

    public function test_a_general_platform_mention_is_supported_by_a_specific_model(): void
    {
        $this->assertNotContains('changed_compatibility', $this->codes(['short_description' => 'A PLA stand for your PlayStation controllers and PS5.']));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unsupportedClaims(): array
    {
        return [
            'english guarantee' => ['Comes with a lifetime guarantee.'],
            'german waterproof' => ['Dieser Ständer ist wasserdicht.'],
            'albanian guarantee' => ['Ky produkt vjen me garanci.'],
            'superlative' => ['Simply the best stand available.'],
            'certification' => ['Fully certified for home use.'],
            'safety' => ['Made from food-safe material.'],
        ];
    }

    #[DataProvider('unsupportedClaims')]
    public function test_unsupported_claims_get_a_cautious_review_warning(string $sentence): void
    {
        $issues = app(ContentQualityChecker::class)->check($this->product(), $this->faithfulFields([
            'long_description' => "This PLA stand measures 10 x 20 cm and is 15 cm tall for PS5. {$sentence}",
        ]));

        $claim = collect($issues)->firstWhere('code', 'unsupported_claim');

        $this->assertNotNull($claim);
        $this->assertSame('review', $claim['level']);
        $this->assertStringStartsWith('May include', $claim['message']);
    }

    public function test_a_claim_stated_in_the_product_information_is_accepted(): void
    {
        $this->assertNotContains('unsupported_claim', $this->codes(
            ['long_description' => 'This waterproof PLA stand measures 10 x 20 cm and is 15 cm tall for PS5.'],
            ['details' => 'Waterproof. Holds PS5 controllers. Size 10 x 20 cm, height 15 cm.'],
        ));
    }

    public function test_invalid_slugs_and_duplicate_keywords_are_flagged(): void
    {
        $codes = $this->codes(['slug' => 'Bad Slug!', 'keywords' => ['Stand', 'stand ']]);

        $this->assertContains('invalid_slug', $codes);
        $this->assertContains('duplicate_keywords', $codes);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function translatedFaithfulContent(): array
    {
        return [
            'english' => ['Measures 10 x 20 cm, 15 centimeters tall, made of PLA for PS5.'],
            'german' => ['Maße 10 × 20 cm, 15 Zentimeter hoch, aus PLA für die PlayStation 5.'],
            'albanian' => ['Përmasat 10 x 20 cm, lartësia 15 centimetra, prej PLA për PS5.'],
        ];
    }

    #[DataProvider('translatedFaithfulContent')]
    public function test_known_facts_stay_consistent_across_languages(string $text): void
    {
        $facts = app(ProductFactExtractor::class);

        $this->assertEqualsCanonicalizing(['10x20 cm', '15 cm'], $facts->measurements($text));
        $this->assertSame(['PLA'], $facts->materials($text));
        $this->assertSame(['playstation5'], array_values(array_intersect($facts->compatibility($text), ['playstation5'])));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function materialTranslations(): array
    {
        return [
            'german leather' => ['Aus echtem Leder gefertigt.', 'Leather'],
            'albanian leather' => ['Prej lëkure të butë.', 'Leather'],
            'german wood' => ['Aus massivem Holz.', 'Wood'],
            'albanian wood' => ['Prej druri.', 'Wood'],
            'german steel' => ['Aus Stahl.', 'Steel'],
        ];
    }

    #[DataProvider('materialTranslations')]
    public function test_translated_materials_are_recognized(string $text, string $material): void
    {
        $this->assertContains($material, app(ProductFactExtractor::class)->materials($text));
    }

    public function test_short_material_codes_only_match_whole_words(): void
    {
        $this->assertSame([], app(ProductFactExtractor::class)->materials('Absolutely a place to display plans.'));
    }

    public function test_3d_and_ordinals_are_not_treated_as_models(): void
    {
        $this->assertSame([], app(ProductFactExtractor::class)->compatibility('A 3D printed stand, our 2nd design.'));
    }
}
