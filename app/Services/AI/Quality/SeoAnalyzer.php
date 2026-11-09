<?php

namespace App\Services\AI\Quality;

/**
 * Rule-based SEO checklist. No AI call is involved, and the targets are common
 * recommendations: the score does not predict search rankings.
 *
 * The score is the sum of the points below, 100 in total. rules() is shown to users.
 */
final class SeoAnalyzer
{
    public const TITLE_LENGTH = [20, 70];

    public const META_TITLE_LENGTH = [50, 60];

    public const META_DESCRIPTION_LENGTH = [140, 160];

    public const SLUG_MAX_LENGTH = 75;

    public const KEYWORD_COUNT = [3, 10];

    public const LONG_DESCRIPTION_WORDS = 50;

    private const META_TITLE_TOLERANCE = 10;

    private const META_DESCRIPTION_TOLERANCE = 20;

    private const REPEATED_TERM_MIN_COUNT = 5;

    private const REPEATED_TERM_MIN_SHARE = 0.04;

    private const STEM_LENGTH = 5;

    private const STOP_WORDS = [
        'this', 'that', 'with', 'from', 'your', 'have', 'will', 'been', 'they', 'their', 'into', 'more', 'each',
        'eine', 'einer', 'einem', 'einen', 'eines', 'oder', 'auch', 'sich', 'nicht', 'sind', 'wird', 'dieser', 'diese', 'dieses', 'durch', 'nach', 'für',
        'është', 'janë', 'këtë', 'kjo', 'ky', 'për', 'nga', 'dhe', 'një', 'duke', 'tuaj', 'tuaja', 'mund', 'edhe',
    ];

    /**
     * @return list<string>
     */
    public static function rules(): array
    {
        return [
            'Title (15): 10 for 20–70 characters, 5 if outside that, and 5 if it shares a word with the product name or keywords.',
            'Meta title (15): 15 for 50–60 characters, 10 for 40–70, 5 otherwise.',
            'Meta description (20): 20 for 140–160 characters, 14 for 120–180, 7 otherwise.',
            'URL slug (10): 10 for lowercase words and hyphens up to 75 characters, 6 if longer, 0 if the format is invalid.',
            'Keywords (20): 8 for having 3–10, 6 for the first keyword appearing in the title or meta title, 6 shared by how many keywords appear in the content.',
            'Repetition (10): 10 if no word is overused, 5 for one overused word, 0 for more. Overused means at least 5 uses and over 4% of the description.',
            'Completeness (10): 4 for a short description of 30–320 characters, 6 for a long description of at least 50 words.',
        ];
    }

    /**
     * @param  array<string, mixed>  $fields  keywords as a list of strings
     * @return array{score: int, checks: list<array<string, mixed>>, warnings: list<string>, suggestions: list<string>}
     */
    public function analyze(array $fields, string $productName): array
    {
        $fields = $this->withDefaults($fields);

        $repetition = $this->repetitionCheck($fields);

        $checks = [
            $this->titleCheck($fields, $productName),
            $this->metaTitleCheck($fields['meta_title']),
            $this->metaDescriptionCheck($fields['meta_description']),
            $this->slugCheck($fields['slug']),
            $this->keywordsCheck($fields),
            $repetition,
            $this->completenessCheck($fields),
        ];

        return [
            'score' => array_sum(array_column($checks, 'points')),
            'checks' => array_map(fn (array $check) => array_diff_key($check, ['suggestion' => 1, 'warning' => 1]), $checks),
            'warnings' => array_values(array_filter(array_column($checks, 'warning'))),
            'suggestions' => array_values(array_filter(array_column($checks, 'suggestion'))),
        ];
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    private function withDefaults(array $fields): array
    {
        foreach (['title', 'short_description', 'long_description', 'meta_title', 'meta_description', 'slug'] as $field) {
            $fields[$field] = is_string($fields[$field] ?? null) ? trim($fields[$field]) : '';
        }

        $fields['keywords'] = array_values(array_filter(
            is_array($fields['keywords'] ?? null) ? $fields['keywords'] : [],
            fn ($keyword) => is_string($keyword) && trim($keyword) !== '',
        ));

        return $fields;
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    private function titleCheck(array $fields, string $productName): array
    {
        $title = $fields['title'];

        if ($title === '') {
            return $this->result('title', 'Title', 'title', 0, 15, 'Add a product title.', suggestion: 'Add a product title.', isEmpty: true);
        }

        $length = mb_strlen($title);
        [$min, $max] = self::TITLE_LENGTH;
        $inRange = $length >= $min && $length <= $max;

        $stems = $this->stems($this->words($title));
        $related = array_merge($this->significantWords($productName), $this->significantWords((string) ($fields['keywords'][0] ?? '')));
        $relevant = $related === [] || array_intersect($this->stems($related), $stems) !== [];

        $message = match (true) {
            ! $inRange => "Aim for {$min}–{$max} characters.",
            ! $relevant => 'The title doesn\'t share a word with the product name or main keyword.',
            default => 'Length and relevance look good.',
        };

        return $this->result(
            'title', 'Title', 'title',
            ($inRange ? 10 : 5) + ($relevant ? 5 : 0),
            15,
            $message,
            suggestion: $inRange ? null : "Adjust the title to about {$min}–{$max} characters.",
            warning: $relevant ? null : 'The title may not clearly match this product. Check that it names the product.',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function metaTitleCheck(string $value): array
    {
        return $this->lengthCheck('meta_title', 'Meta title', $value, self::META_TITLE_LENGTH, self::META_TITLE_TOLERANCE, [15, 10, 5]);
    }

    /**
     * @return array<string, mixed>
     */
    private function metaDescriptionCheck(string $value): array
    {
        return $this->lengthCheck('meta_description', 'Meta description', $value, self::META_DESCRIPTION_LENGTH, self::META_DESCRIPTION_TOLERANCE, [20, 14, 7]);
    }

    /**
     * @param  array{int, int}  $target
     * @param  array{int, int, int}  $points  in target, within tolerance, otherwise
     * @return array<string, mixed>
     */
    private function lengthCheck(string $key, string $label, string $value, array $target, int $tolerance, array $points): array
    {
        if ($value === '') {
            return $this->result($key, $label, $key, 0, $points[0], "Add a {$this->lower($label)}.", suggestion: "Add a {$this->lower($label)}.", isEmpty: true);
        }

        [$min, $max] = $target;
        $length = mb_strlen($value);

        if ($length >= $min && $length <= $max) {
            return $this->result($key, $label, $key, $points[0], $points[0], 'Within the recommended length.');
        }

        $earned = $length >= $min - $tolerance && $length <= $max + $tolerance ? $points[1] : $points[2];
        $direction = $length < $min ? 'making it more descriptive' : 'shortening it';

        return $this->result(
            $key, $label, $key, $earned, $points[0],
            "{$length} characters. The recommendation is {$min}–{$max}.",
            suggestion: "Consider {$direction}: the {$this->lower($label)} is {$length} characters, and {$min}–{$max} is recommended.",
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function slugCheck(string $slug): array
    {
        if ($slug === '') {
            return $this->result('slug', 'URL slug', 'slug', 0, 10, 'Add a URL slug.', suggestion: 'Add a URL slug.', isEmpty: true);
        }

        if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) !== 1) {
            return $this->result('slug', 'URL slug', 'slug', 0, 10, 'Use only lowercase letters, numbers and hyphens. It will be cleaned up when saved.', suggestion: 'Use lowercase words separated by hyphens in the URL slug.');
        }

        if (mb_strlen($slug) > self::SLUG_MAX_LENGTH) {
            return $this->result('slug', 'URL slug', 'slug', 6, 10, 'Valid, but longer than recommended.', suggestion: 'Consider a shorter URL slug, up to '.self::SLUG_MAX_LENGTH.' characters.');
        }

        return $this->result('slug', 'URL slug', 'slug', 10, 10, 'Clean format and length.');
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    private function keywordsCheck(array $fields): array
    {
        $keywords = $fields['keywords'];

        if ($keywords === []) {
            return $this->result('keywords', 'Keywords', 'keywords', 0, 20, 'Add some keywords.', suggestion: 'Add 3–10 relevant keywords.', isEmpty: true);
        }

        [$min, $max] = self::KEYWORD_COUNT;
        $count = count($keywords);
        $countPoints = $count >= $min && $count <= $max ? 8 : 4;

        $headingStems = $this->stems($this->words($fields['title'].' '.$fields['meta_title']));
        $contentStems = $this->stems($this->words(implode(' ', [$fields['title'], $fields['short_description'], $fields['long_description'], $fields['meta_title'], $fields['meta_description']])));

        $primaryInHeading = $this->isPhrasePresent($keywords[0], $headingStems);
        $found = count(array_filter($keywords, fn (string $keyword) => $this->isPhrasePresent($keyword, $contentStems)));
        $coveragePoints = (int) round($found / $count * 6);

        $suggestion = match (true) {
            $countPoints < 8 => "Use {$min}–{$max} keywords. There are {$count} now.",
            ! $primaryInHeading => 'Consider using the first keyword in the title or meta title.',
            $coveragePoints < 6 => 'Some keywords don\'t appear in the content. Remove them or work them in naturally.',
            default => null,
        };

        return $this->result(
            'keywords', 'Keywords', 'keywords',
            $countPoints + ($primaryInHeading ? 6 : 0) + $coveragePoints,
            20,
            "{$count} keywords, {$found} found in the content.",
            suggestion: $suggestion,
        );
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    private function repetitionCheck(array $fields): array
    {
        $words = $this->words($fields['short_description'].' '.$fields['long_description']);

        if ($words === []) {
            return $this->result('repetition', 'Repetition', 'long_description', 0, 10, 'No description to check yet.', isEmpty: true);
        }

        $total = count($words);
        $repeated = [];

        foreach (array_count_values($words) as $word => $count) {
            $word = (string) $word;

            if (mb_strlen($word) >= 4 && ! in_array($word, self::STOP_WORDS, true)
                && $count >= self::REPEATED_TERM_MIN_COUNT && $count / $total > self::REPEATED_TERM_MIN_SHARE) {
                $repeated[] = $word;
            }
        }

        if ($repeated === []) {
            return $this->result('repetition', 'Repetition', 'long_description', 10, 10, 'No overused words.');
        }

        $list = implode(', ', $repeated);

        return $this->result(
            'repetition', 'Repetition', 'long_description',
            count($repeated) === 1 ? 5 : 0,
            10,
            "Overused: {$list}.",
            suggestion: 'Review repetitive terminology and vary the wording.',
            warning: "These words may be overused: {$list}.",
        );
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    private function completenessCheck(array $fields): array
    {
        $shortLength = mb_strlen($fields['short_description']);
        $wordCount = count($this->words($fields['long_description']));

        $shortPoints = match (true) {
            $shortLength >= 30 && $shortLength <= 320 => 4,
            $shortLength > 0 => 2,
            default => 0,
        };

        $longPoints = match (true) {
            $wordCount >= self::LONG_DESCRIPTION_WORDS => 6,
            $wordCount >= 25 => 3,
            $wordCount > 0 => 1,
            default => 0,
        };

        $points = $shortPoints + $longPoints;

        return $this->result(
            'completeness', 'Completeness', 'long_description',
            $points,
            10,
            "Long description has {$wordCount} words.",
            suggestion: $longPoints < 6 ? 'Consider adding more factual detail to the long description, up to at least '.self::LONG_DESCRIPTION_WORDS.' words.' : ($shortPoints < 4 ? 'Aim for a short description of 30–320 characters.' : null),
            isEmpty: $points === 0,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function result(string $key, string $label, string $field, int $points, int $max, string $message, ?string $suggestion = null, ?string $warning = null, bool $isEmpty = false): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'field' => $field,
            'points' => $points,
            'max' => $max,
            'status' => match (true) {
                $points === $max => 'good',
                $isEmpty => 'missing',
                default => 'needs_improvement',
            },
            'message' => $message,
            'suggestion' => $suggestion,
            'warning' => $warning,
        ];
    }

    private function lower(string $label): string
    {
        return mb_strtolower($label);
    }

    /**
     * @return list<string>
     */
    private function words(string $text): array
    {
        preg_match_all('/[\p{L}\p{N}]+/u', mb_strtolower($text), $matches);

        return $matches[0];
    }

    /**
     * @return list<string>
     */
    private function significantWords(string $text): array
    {
        return array_values(array_filter($this->words($text), fn (string $word) => mb_strlen($word) >= 3));
    }

    /**
     * Compares word beginnings, so German and Albanian word endings still match.
     *
     * @param  list<string>  $words
     * @return list<string>
     */
    private function stems(array $words): array
    {
        return array_values(array_unique(array_map(fn (string $word) => mb_substr($word, 0, self::STEM_LENGTH), $words)));
    }

    /**
     * @param  list<string>  $contentStems
     */
    private function isPhrasePresent(string $phrase, array $contentStems): bool
    {
        $words = $this->significantWords($phrase) ?: $this->words($phrase);

        return $words !== [] && array_diff($this->stems($words), $contentStems) === [];
    }
}
