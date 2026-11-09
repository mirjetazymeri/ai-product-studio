<?php

namespace App\Services\Catalog;

/**
 * Suggests destinations for source columns using a fixed list of common names.
 */
final class ColumnMapper
{
    public const PRODUCT_FIELDS = [
        'external_id' => 'External Product ID',
        'original_name' => 'Product Name',
        'sku' => 'SKU',
        'category' => 'Category',
        'material' => 'Material',
        'details' => 'Product Details',
        'status' => 'Status',
    ];

    public const CONTENT_FIELDS = [
        'title' => 'Title',
        'short_description' => 'Short Description',
        'long_description' => 'Long Description',
        'meta_title' => 'Meta Title',
        'meta_description' => 'Meta Description',
        'slug' => 'Slug',
        'keywords' => 'Keywords',
    ];

    public const REQUIRED_FIELDS = ['original_name'];

    private const ALIASES = [
        'external_id' => ['external_id', 'external_product_id', 'external_reference', 'product_id', 'id', 'item_id'],
        'original_name' => ['original_name', 'product_name', 'name', 'product', 'item_name', 'produktname', 'emri'],
        'sku' => ['sku', 'product_sku', 'item_sku', 'article_number', 'artikelnummer', 'kodi'],
        'category' => ['category', 'product_category', 'categories', 'kategorie', 'kategoria'],
        'material' => ['material', 'materials', 'product_material', 'materiali'],
        'details' => ['details', 'product_details', 'description', 'product_description', 'beschreibung', 'pershkrimi'],
        'status' => ['status', 'product_status', 'state'],
        'language' => ['language', 'lang', 'locale', 'language_code', 'sprache', 'gjuha'],
        'title' => ['title', 'content_title', 'product_title', 'titel', 'titulli'],
        'short_description' => ['short_description', 'short_desc', 'excerpt', 'summary'],
        'long_description' => ['long_description', 'long_desc', 'full_description'],
        'meta_title' => ['meta_title', 'seo_title'],
        'meta_description' => ['meta_description', 'seo_description'],
        'slug' => ['slug', 'url_slug', 'handle', 'url_key'],
        'keywords' => ['keywords', 'tags', 'meta_keywords', 'seo_keywords'],
    ];

    /**
     * @return array<string, string> every destination, keyed by field name
     */
    public static function destinations(): array
    {
        return [...self::PRODUCT_FIELDS, 'language' => 'Language', ...self::CONTENT_FIELDS];
    }

    /**
     * @param  list<string>  $headers
     * @return array<int, ?string> a suggested destination per column index, each used at most once
     */
    public function suggest(array $headers): array
    {
        $suggestions = [];
        $used = [];

        foreach ($headers as $index => $header) {
            $normalised = trim((string) preg_replace('/[^a-z0-9]+/', '_', mb_strtolower($header)), '_');
            $field = null;

            foreach (self::ALIASES as $destination => $aliases) {
                if (! isset($used[$destination]) && in_array($normalised, $aliases, true)) {
                    $field = $destination;
                    $used[$destination] = true;

                    break;
                }
            }

            $suggestions[$index] = $field;
        }

        return $suggestions;
    }
}
