<?php

namespace App\Services\WooCommerce;

use App\Models\Product;
use App\Models\ProductContent;
use App\Models\WooCommerceProductLink;
use Illuminate\Support\Str;

/**
 * Translates between WooCommerce's product shape and this application's.
 */
final class WooCommerceProductMapper
{
    public const FIELD_LABELS = [
        'name' => 'Product title',
        'description' => 'Long description',
        'short_description' => 'Short description',
        'slug' => 'URL slug',
    ];

    private const SEO_LABELS = [
        'meta_title' => 'Meta title',
        'meta_description' => 'Meta description',
    ];

    /**
     * Keeps only descriptive fields and identifiers. Prices, stock, images,
     * customers and arbitrary metadata are never stored.
     *
     * @param  array<string, mixed>  $remote
     * @return array<string, mixed>|null null when the record has no usable ID or name
     */
    public function toStaged(array $remote): ?array
    {
        $id = $remote['id'] ?? null;
        $name = $this->text($remote['name'] ?? null);

        if (! is_int($id) || $id < 1 || $name === '') {
            return null;
        }

        return [
            'id' => $id,
            'name' => $name,
            'sku' => $this->text($remote['sku'] ?? null) ?: null,
            'type' => $this->text($remote['type'] ?? null) ?: 'unknown',
            'status' => $this->text($remote['status'] ?? null) ?: null,
            'categories' => $this->categories($remote['categories'] ?? null),
            'description' => $this->plainText((string) ($remote['description'] ?? '')),
            'short_description' => $this->plainText((string) ($remote['short_description'] ?? '')),
            'slug' => $this->text($remote['slug'] ?? null) ?: null,
            'permalink' => is_string($remote['permalink'] ?? null) ? mb_substr($remote['permalink'], 0, 500) : null,
            'modified_gmt' => is_string($remote['date_modified_gmt'] ?? null) ? mb_substr($remote['date_modified_gmt'], 0, 40) : null,
            'variation_count' => is_array($remote['variations'] ?? null) ? count($remote['variations']) : 0,
            'language' => is_string($remote['lang'] ?? null) ? mb_substr($remote['lang'], 0, 10) : null,
            'translations' => $this->translations($remote['translations'] ?? null),
        ];
    }

    public function syncModeFor(string $type): string
    {
        return match ($type) {
            'simple' => WooCommerceProductLink::MODE_FULL,
            'variable' => WooCommerceProductLink::MODE_DESCRIPTIVE,
            default => WooCommerceProductLink::MODE_READ_ONLY,
        };
    }

    /**
     * @param  array<string, mixed>  $staged
     * @return array<string, mixed>
     */
    public function localAttributes(array $staged): array
    {
        return [
            'original_name' => mb_substr($staged['name'], 0, 255),
            'category' => $staged['categories'] === [] ? null : mb_substr(implode(', ', $staged['categories']), 0, 120),
            'details' => mb_substr(($staged['description'] ?: $staged['short_description']) ?: '', 0, 10000) ?: null,
            'status' => $staged['status'] === 'publish' ? 'published' : 'draft',
        ];
    }

    /**
     * @param  array<string, mixed>  $staged
     * @return array<string, mixed>
     */
    public function localContent(array $staged): array
    {
        return array_filter([
            'title' => mb_substr($staged['name'], 0, 255),
            'short_description' => mb_substr((string) $staged['short_description'], 0, 1000),
            'long_description' => mb_substr((string) $staged['description'], 0, 20000),
            'slug' => $staged['slug'] === null ? null : mb_substr(Str::slug(rawurldecode($staged['slug'])), 0, 255),
        ], filled(...));
    }

    /**
     * @param  array<string, mixed>  $staged
     * @return list<string> labels of the local fields a refresh would change
     */
    public function changedLocalFields(Product $product, array $staged): array
    {
        $labels = ['original_name' => 'Product name', 'category' => 'Category', 'details' => 'Details', 'status' => 'Status'];
        $changes = [];

        foreach ($this->localAttributes($staged) as $field => $value) {
            $current = $product->{$field};
            $current = $current instanceof \BackedEnum ? $current->value : $current;

            if ((string) $current !== (string) $value) {
                $changes[] = $labels[$field];
            }
        }

        return $changes;
    }

    /**
     * Builds the field-level comparison between the live remote product and approved content.
     *
     * @param  array<string, mixed>  $remote
     * @return array<string, array<string, mixed>>
     */
    public function compare(array $remote, ProductContent $content): array
    {
        $fields = [];

        foreach ($this->proposedValues($content) as $field => $wire) {
            $raw = (string) ($remote[$field] ?? '');

            $fields[$field] = [
                'label' => self::FIELD_LABELS[$field],
                'supported' => true,
                'remote_raw' => $raw,
                'remote_hash' => $this->hash($raw),
                'remote_display' => $this->display($field, $raw),
                'proposed_wire' => $wire,
                'proposed_display' => $this->display($field, $wire),
                'changed' => ! $this->sameValue($field, $raw, $wire),
                'selected' => false,
            ];
        }

        foreach (self::SEO_LABELS as $field => $label) {
            if (filled($content->{$field})) {
                $fields[$field] = [
                    'label' => $label,
                    'supported' => false,
                    'remote_display' => null,
                    'proposed_display' => $content->{$field},
                    'changed' => false,
                    'selected' => false,
                ];
            }
        }

        return $fields;
    }

    /**
     * @return array<string, string> values exactly as they would be sent to the store
     */
    public function proposedValues(ProductContent $content): array
    {
        return array_filter([
            'name' => (string) $content->title,
            'description' => $this->textToHtml((string) $content->long_description),
            'short_description' => $this->textToHtml((string) $content->short_description),
            'slug' => (string) $content->slug,
        ], filled(...));
    }

    public function sameValue(string $field, string $a, string $b): bool
    {
        return $this->canonical($field, $a) === $this->canonical($field, $b);
    }

    public function hash(string $raw): string
    {
        return hash('sha256', $raw);
    }

    public function display(string $field, string $raw): string
    {
        return $field === 'slug' ? rawurldecode($raw) : $this->plainText($raw);
    }

    /**
     * Local content is plain text, so it is escaped and wrapped in paragraphs for the store.
     */
    public function textToHtml(string $text): string
    {
        $paragraphs = preg_split('/\R{2,}/u', trim($text), flags: PREG_SPLIT_NO_EMPTY) ?: [];

        return implode("\n", array_map(
            fn (string $paragraph) => '<p>'.nl2br(htmlspecialchars(trim($paragraph), ENT_NOQUOTES, 'UTF-8'), false).'</p>',
            $paragraphs,
        ));
    }

    public function plainText(string $html): string
    {
        $text = (string) preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', $html);
        $text = (string) preg_replace('#<br\s*/?>#i', "\n", $text);
        $text = (string) preg_replace('#</(p|div|li|h[1-6]|ul|ol|blockquote)>#i', "\n\n", $text);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string) preg_replace('/[^\S\n]+/u', ' ', str_replace(["\r\n", "\r", "\u{00A0}"], ["\n", "\n", ' '], $text));
        $text = (string) preg_replace('/ *\n */', "\n", $text);

        return trim((string) preg_replace('/\n{3,}/', "\n\n", $text));
    }

    /**
     * Markup and whitespace differences alone don't count as a change of content.
     */
    private function canonical(string $field, string $value): string
    {
        if ($field === 'slug') {
            return mb_strtolower(rawurldecode($value));
        }

        return trim((string) preg_replace('/\s+/u', ' ', $this->plainText($value)));
    }

    private function text(mixed $value): string
    {
        return is_string($value) ? trim(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8')) : '';
    }

    /**
     * @return list<string>
     */
    private function categories(mixed $categories): array
    {
        if (! is_array($categories)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn ($category) => is_array($category) ? $this->text($category['name'] ?? null) : '',
            $categories,
        )));
    }

    /**
     * @return array<string, int>
     */
    private function translations(mixed $translations): array
    {
        if (! is_array($translations)) {
            return [];
        }

        return array_map('intval', array_filter($translations, fn ($id, $code) => is_string($code) && is_numeric($id), ARRAY_FILTER_USE_BOTH));
    }
}
