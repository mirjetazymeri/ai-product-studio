<?php

namespace App\Services\WooCommerce;

use App\Enums\WooCommerceError;
use App\Models\WooCommerceStore;
use App\Services\WooCommerce\Exceptions\WooCommerceException;
use App\Services\WooCommerce\Transport\DemoWooCommerceTransport;
use App\Services\WooCommerce\Transport\RealWooCommerceTransport;
use App\Services\WooCommerce\Transport\WooCommerceTransport;

/**
 * The application's single way to reach a store. It chooses the transport from the
 * store itself, so a demo store can never use the HTTP client and the reverse.
 */
class WooCommerceService
{
    /**
     * The only product fields this application may ever send to a store. Prices, stock,
     * images, SKU, status, shipping, tax, attributes and variations can't be written.
     */
    public const WRITABLE_FIELDS = ['name', 'description', 'short_description', 'slug'];

    public function __construct(
        private readonly RealWooCommerceTransport $real,
        private readonly DemoWooCommerceTransport $demo,
    ) {}

    /**
     * @return array{products: list<array<string, mixed>>, total_pages: int}
     *
     * @throws WooCommerceException
     */
    public function listProducts(WooCommerceStore $store, int $page, int $perPage): array
    {
        return $this->transportFor($store)->listProducts($store, $page, $perPage);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws WooCommerceException
     */
    public function getProduct(WooCommerceStore $store, int $remoteId): array
    {
        return $this->transportFor($store)->getProduct($store, $remoteId);
    }

    /**
     * Sends a partial update of allowlisted fields only.
     *
     * @param  array<string, string>  $fields
     * @return array<string, mixed>
     *
     * @throws WooCommerceException
     */
    public function updateProduct(WooCommerceStore $store, int $remoteId, array $fields): array
    {
        $transport = $this->transportFor($store);
        $allowed = array_intersect_key($fields, array_flip(self::WRITABLE_FIELDS));

        if ($allowed === []) {
            throw new WooCommerceException(WooCommerceError::Rejected);
        }

        return $transport->updateProduct($store, $remoteId, $allowed);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws WooCommerceException
     */
    public function systemStatus(WooCommerceStore $store): array
    {
        return $this->transportFor($store)->systemStatus($store);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws WooCommerceException
     */
    public function siteIndex(WooCommerceStore $store): array
    {
        return $this->transportFor($store)->siteIndex($store);
    }

    private function transportFor(WooCommerceStore $store): WooCommerceTransport
    {
        if ($store->isDisconnected()) {
            throw new WooCommerceException(WooCommerceError::Disconnected);
        }

        return $store->isDemo() ? $this->demo : $this->real;
    }
}
