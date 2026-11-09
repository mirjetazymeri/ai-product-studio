<?php

namespace App\Services\WooCommerce\Transport;

use App\Models\WooCommerceStore;
use App\Services\WooCommerce\Exceptions\WooCommerceException;

/**
 * How WooCommerce's REST responses reach this application. Everything above this
 * boundary (mapping, import, preview, sync, conflicts) is shared by every transport.
 */
interface WooCommerceTransport
{
    /**
     * @return array{products: list<array<string, mixed>>, total_pages: int}
     *
     * @throws WooCommerceException
     */
    public function listProducts(WooCommerceStore $store, int $page, int $perPage): array;

    /**
     * @return array<string, mixed>
     *
     * @throws WooCommerceException
     */
    public function getProduct(WooCommerceStore $store, int $remoteId): array;

    /**
     * @param  array<string, string>  $fields  already limited to the writable allowlist
     * @return array<string, mixed>
     *
     * @throws WooCommerceException
     */
    public function updateProduct(WooCommerceStore $store, int $remoteId, array $fields): array;

    /**
     * @return array<string, mixed>
     *
     * @throws WooCommerceException
     */
    public function systemStatus(WooCommerceStore $store): array;

    /**
     * @return array<string, mixed>
     *
     * @throws WooCommerceException
     */
    public function siteIndex(WooCommerceStore $store): array;
}
