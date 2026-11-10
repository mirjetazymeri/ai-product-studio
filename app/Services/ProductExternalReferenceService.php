<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Product;
use App\Models\ProductExternalReference;

/**
 * Explicit mapping between a local product and its ID in one outside system. An ID is
 * only meaningful within its source, and a match is never made automatically.
 */
class ProductExternalReferenceService
{
    /** Written by the store integrations. API clients can't create or remove them. */
    public const RESERVED_PREFIXES = ['woocommerce-', 'shopify-'];

    public const SOURCE_FORMAT = '/^[a-z0-9][a-z0-9._-]{0,49}$/';

    public function assign(Product $product, string $source, string $externalId): ProductExternalReference
    {
        $this->ensureWritable($source);

        $mapped = ProductExternalReference::query()
            ->where('user_id', $product->user_id)
            ->where('source', $source)
            ->where('external_id', $externalId)
            ->first();

        if ($mapped !== null && $mapped->product_id !== $product->id) {
            throw new ApiException(409, 'external_reference_conflict', 'This external ID is already mapped to another product in this source.', ['product_id' => $mapped->product_id]);
        }

        return $product->externalReferences()->updateOrCreate(
            ['source' => $source],
            ['user_id' => $product->user_id, 'external_id' => $externalId],
        );
    }

    public function remove(Product $product, string $source): void
    {
        $this->ensureWritable($source);

        $product->externalReferences()->where('source', $source)->delete();
    }

    public static function isReserved(string $source): bool
    {
        return str_starts_with($source, self::RESERVED_PREFIXES[0]) || str_starts_with($source, self::RESERVED_PREFIXES[1]);
    }

    private function ensureWritable(string $source): void
    {
        if (preg_match(self::SOURCE_FORMAT, $source) !== 1 || self::isReserved($source)) {
            throw new ApiException(422, 'invalid_external_source', 'This external source name can\'t be used.');
        }
    }
}
