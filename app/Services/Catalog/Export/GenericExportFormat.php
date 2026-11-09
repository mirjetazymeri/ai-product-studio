<?php

namespace App\Services\Catalog\Export;

use App\Enums\ContentLanguage;
use App\Enums\ContentSource;
use App\Enums\ExportContentScope;
use App\Models\Product;
use App\Models\ProductContent;

/**
 * One row per product and language. Documented in docs/catalog-export-format.md.
 */
final class GenericExportFormat implements CatalogExportFormat
{
    private const PRODUCT_HEADERS = ['local_product_id', 'external_source', 'external_id', 'product_name', 'sku', 'category', 'material', 'details', 'status'];

    private const CONTENT_HEADERS = ['language', 'title', 'short_description', 'long_description', 'meta_title', 'meta_description', 'slug', 'keywords', 'content_source', 'content_status'];

    private const CONTENT_FIELDS = ['title', 'short_description', 'long_description', 'meta_title', 'meta_description', 'slug'];

    public function headers(ExportContentScope $scope): array
    {
        return $scope->includesContent() ? [...self::PRODUCT_HEADERS, ...self::CONTENT_HEADERS] : self::PRODUCT_HEADERS;
    }

    public function rows(Product $product, ExportContentScope $scope, array $languages, array $drafts): iterable
    {
        $base = $this->productCells($product);

        if (! $scope->includesContent()) {
            yield $base;

            return;
        }

        $wroteContent = false;

        foreach ($languages as $code) {
            $saved = $product->contentFor(ContentLanguage::from($code));

            if ($saved !== null && $this->includesSaved($saved, $scope)) {
                $wroteContent = true;

                yield [...$base, ...$this->savedCells($code, $saved)];
            }

            if ($scope->includesDrafts() && isset($drafts[$code])) {
                $wroteContent = true;

                yield [...$base, ...$this->draftCells($code, $drafts[$code])];
            }
        }

        // A selected product stays in the file even when it has no content in scope.
        if (! $wroteContent) {
            yield [...$base, ...array_fill(0, count(self::CONTENT_HEADERS), null)];
        }
    }

    private function includesSaved(ProductContent $content, ExportContentScope $scope): bool
    {
        return $scope !== ExportContentScope::Generated || $content->source === ContentSource::Ai;
    }

    /**
     * @return list<mixed>
     */
    private function productCells(Product $product): array
    {
        $reference = $product->externalReferences->first();

        return [
            $product->id,
            $reference?->source,
            $reference?->external_id,
            $product->original_name,
            $product->sku,
            $product->category,
            $product->material,
            $product->details,
            $product->status->value,
        ];
    }

    /**
     * @return list<mixed>
     */
    private function savedCells(string $code, ProductContent $content): array
    {
        return [
            $code,
            ...array_map(fn (string $field) => $content->{$field}, self::CONTENT_FIELDS),
            implode(', ', $content->keywords ?? []),
            $content->source->value,
            'approved',
        ];
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return list<mixed>
     */
    private function draftCells(string $code, array $draft): array
    {
        return [
            $code,
            ...array_map(fn (string $field) => $draft[$field] ?? null, self::CONTENT_FIELDS),
            implode(', ', $draft['keywords'] ?? []),
            ContentSource::Ai->value,
            'pending_review',
        ];
    }
}
