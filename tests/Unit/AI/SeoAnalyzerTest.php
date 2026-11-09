<?php

namespace Tests\Unit\AI;

use App\Services\AI\Quality\SeoAnalyzer;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SeoAnalyzerTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function strongFields(array $overrides = []): array
    {
        $body = collect(range(1, 60))->map(fn (int $number) => "detail{$number}")->implode(' ');

        return array_replace([
            'title' => 'Leather Tote Bag with Brass Hardware',
            'short_description' => 'A hand-stitched leather tote with brass hardware.',
            'long_description' => "Leather tote with brass fittings. {$body}",
            'meta_title' => str_pad('Leather Tote Bag ', 55, 'x'),
            'meta_description' => str_pad('Leather tote with brass hardware ', 150, 'y'),
            'slug' => 'leather-tote-bag',
            'keywords' => ['leather tote', 'brass hardware', 'tote bag'],
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private function check(array $report, string $key): array
    {
        return collect($report['checks'])->firstWhere('key', $key);
    }

    private function analyze(array $overrides = []): array
    {
        return app(SeoAnalyzer::class)->analyze($this->strongFields($overrides), 'Leather Tote');
    }

    public function test_strong_content_scores_full_marks_with_no_suggestions(): void
    {
        $report = $this->analyze();

        $this->assertSame(100, $report['score']);
        $this->assertSame([], $report['suggestions']);
        $this->assertSame([], $report['warnings']);
    }

    public function test_the_rule_weights_add_up_to_100(): void
    {
        $this->assertSame(100, array_sum(array_column($this->analyze()['checks'], 'max')));
    }

    public function test_empty_content_scores_zero(): void
    {
        $report = app(SeoAnalyzer::class)->analyze([], 'Leather Tote');

        $this->assertSame(0, $report['score']);
        $this->assertSame('missing', $this->check($report, 'meta_title')['status']);
    }

    /**
     * @return array<string, array{int, int}>
     */
    public static function metaTitleLengths(): array
    {
        return [
            'in target' => [55, 15],
            'at lower edge' => [50, 15],
            'near target' => [45, 10],
            'far below' => [20, 5],
            'far above' => [90, 5],
        ];
    }

    #[DataProvider('metaTitleLengths')]
    public function test_meta_title_points_follow_the_documented_bands(int $length, int $points): void
    {
        $report = $this->analyze(['meta_title' => str_pad('Leather Tote ', $length, 'x')]);

        $this->assertSame($points, $this->check($report, 'meta_title')['points']);
    }

    /**
     * @return array<string, array{int, int}>
     */
    public static function metaDescriptionLengths(): array
    {
        return [
            'in target' => [150, 20],
            'near target' => [125, 14],
            'too short' => [60, 7],
        ];
    }

    #[DataProvider('metaDescriptionLengths')]
    public function test_meta_description_points_follow_the_documented_bands(int $length, int $points): void
    {
        $report = $this->analyze(['meta_description' => str_pad('Leather tote ', $length, 'y')]);

        $this->assertSame($points, $this->check($report, 'meta_description')['points']);
    }

    public function test_lengths_count_characters_not_bytes(): void
    {
        $report = $this->analyze(['meta_title' => str_repeat('ë', 55)]);

        $this->assertSame(15, $this->check($report, 'meta_title')['points']);
    }

    public function test_a_short_meta_description_produces_an_actionable_suggestion(): void
    {
        $report = $this->analyze(['meta_description' => 'Leather tote.']);

        $this->assertSame('needs_improvement', $this->check($report, 'meta_description')['status']);
        $this->assertStringContainsString('making it more descriptive', implode(' ', $report['suggestions']));
    }

    public function test_an_invalid_slug_scores_zero_and_a_long_one_loses_points(): void
    {
        $this->assertSame(0, $this->check($this->analyze(['slug' => 'Bad Slug!']), 'slug')['points']);
        $this->assertSame(6, $this->check($this->analyze(['slug' => str_repeat('a', 80)]), 'slug')['points']);
    }

    public function test_an_unrelated_title_loses_relevance_points_and_warns(): void
    {
        $report = $this->analyze(['title' => 'Completely different wording here', 'keywords' => ['something else']]);

        $this->assertSame(10, $this->check($report, 'title')['points']);
        $this->assertNotEmpty($report['warnings']);
    }

    public function test_keyword_matching_tolerates_german_and_albanian_endings(): void
    {
        $report = $this->analyze([
            'title' => 'Ledertaschen aus Handarbeit',
            'meta_title' => str_pad('Ledertaschen ', 55, 'x'),
            'keywords' => ['ledertasche'],
        ]);

        $check = $this->check($report, 'keywords');

        // 4 for the count, 6 for the first keyword in a heading, 6 for full coverage.
        $this->assertSame(16, $check['points']);
    }

    public function test_keywords_missing_from_the_content_reduce_coverage(): void
    {
        $report = $this->analyze(['keywords' => ['leather tote', 'wireless charger', 'garden gnome', 'desk lamp']]);

        // 8 for the count, 6 for the first keyword, and 1 of 4 found rounds to 2.
        $this->assertSame(16, $this->check($report, 'keywords')['points']);
    }

    public function test_one_overused_word_loses_half_the_repetition_points(): void
    {
        $body = collect(range(1, 50))->map(fn (int $number) => "detail{$number}")->implode(' ');
        $report = $this->analyze(['long_description' => str_repeat('leather ', 6).$body]);

        $this->assertSame(5, $this->check($report, 'repetition')['points']);
        $this->assertStringContainsString('leather', implode(' ', $report['warnings']));
        $this->assertContains('Review repetitive terminology and vary the wording.', $report['suggestions']);
    }

    public function test_common_words_are_not_counted_as_repetition(): void
    {
        $body = collect(range(1, 50))->map(fn (int $number) => "detail{$number}")->implode(' ');
        $report = $this->analyze(['long_description' => str_repeat('oder ', 8).$body]);

        $this->assertSame(10, $this->check($report, 'repetition')['points']);
    }

    public function test_missing_is_only_used_for_empty_fields(): void
    {
        $report = $this->analyze(['slug' => 'Bad Slug!']);

        $this->assertSame(0, $this->check($report, 'slug')['points']);
        $this->assertSame('needs_improvement', $this->check($report, 'slug')['status']);
    }

    public function test_a_thin_long_description_loses_completeness_points(): void
    {
        $report = $this->analyze(['long_description' => 'Just a few words here.']);

        $this->assertSame(5, $this->check($report, 'completeness')['points']);
    }

    public function test_the_same_content_always_gets_the_same_score(): void
    {
        $this->assertSame($this->analyze()['score'], $this->analyze()['score']);
    }
}
