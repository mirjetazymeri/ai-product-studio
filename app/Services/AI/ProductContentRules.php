<?php

namespace App\Services\AI;

use App\Enums\ContentLanguage;
use Illuminate\Support\Str;

/**
 * One definition of valid AI content, applied to Gemini's output and to the reviewed form.
 * The limits match the product_contents columns.
 */
final class ProductContentRules
{
    /**
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'short_description' => ['required', 'string', 'max:1000'],
            'long_description' => ['required', 'string', 'max:20000'],
            'meta_title' => ['required', 'string', 'max:255'],
            'meta_description' => ['required', 'string', 'max:500'],
            'slug' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'keywords' => ['required', 'array', 'min:1', 'max:30'],
            'keywords.*' => ['required', 'string', 'max:64'],
        ];
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    public static function normalise(array $fields, ContentLanguage $language): array
    {
        foreach (array_keys(self::rules()) as $field) {
            if (is_string($fields[$field] ?? null)) {
                $fields[$field] = trim($fields[$field]);
            }
        }

        $fields['slug'] = self::slug($fields, $language);
        $fields['keywords'] = self::keywords($fields['keywords'] ?? null);

        return $fields;
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private static function slug(array $fields, ContentLanguage $language): ?string
    {
        $source = collect([$fields['slug'] ?? null, $fields['title'] ?? null])
            ->first(fn ($value) => is_string($value) && filled($value));

        return $source === null ? null : (Str::slug($source, '-', $language->value) ?: null);
    }

    private static function keywords(mixed $keywords): mixed
    {
        if (is_string($keywords)) {
            $keywords = explode(',', $keywords);
        }

        if (! is_array($keywords)) {
            return $keywords;
        }

        return collect($keywords)
            ->map(fn ($keyword) => is_string($keyword) ? trim($keyword) : $keyword)
            ->reject(fn ($keyword) => $keyword === '' || $keyword === null)
            ->unique()
            ->values()
            ->all();
    }
}
