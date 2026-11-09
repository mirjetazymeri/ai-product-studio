<?php

namespace App\Services\WooCommerce\Transport;

use App\Enums\WooCommerceError;
use App\Models\WooCommerceDemoProduct;
use App\Models\WooCommerceStore;
use App\Services\WooCommerce\Exceptions\WooCommerceException;
use App\Services\WooCommerce\WooCommerceService;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Answers like a WooCommerce REST API, from the store's own rows in the database.
 * It has no HTTP client, so a demo store can't reach the network.
 */
class DemoWooCommerceTransport implements WooCommerceTransport
{
    /** Small enough that the 14 sample records span two pages. */
    public const PAGE_SIZE = 7;

    public function listProducts(WooCommerceStore $store, int $page, int $perPage): array
    {
        $this->ensureAvailable($store);

        $size = min(self::PAGE_SIZE, max(1, $perPage));
        $query = $store->demoProducts()->orderBy('remote_id');
        $total = (clone $query)->count();

        return [
            'products' => $query->forPage(max(1, $page), $size)->get()->map->payload->all(),
            'total_pages' => max(1, (int) ceil($total / $size)),
        ];
    }

    public function getProduct(WooCommerceStore $store, int $remoteId): array
    {
        $this->ensureAvailable($store);

        return $this->find($store, $remoteId)->payload;
    }

    public function updateProduct(WooCommerceStore $store, int $remoteId, array $fields): array
    {
        $this->ensureAvailable($store);

        $allowed = array_intersect_key($fields, array_flip(WooCommerceService::WRITABLE_FIELDS));

        if ($allowed === []) {
            throw $this->httpError(400, 'rest_invalid_param');
        }

        return DB::transaction(function () use ($store, $remoteId, $allowed) {
            $record = $this->find($store, $remoteId, lock: true);
            $modified = now()->utc()->format('Y-m-d\TH:i:s');

            $record->update(['payload' => array_replace($record->payload, $allowed, [
                'date_modified_gmt' => $modified,
                'date_modified' => $modified,
            ])]);

            return $record->payload;
        });
    }

    public function systemStatus(WooCommerceStore $store): array
    {
        $this->ensureAvailable($store);

        return [
            'environment' => ['version' => '9.0.0-demo'],
            'active_plugins' => [
                ['plugin' => 'woocommerce/woocommerce.php', 'name' => 'WooCommerce'],
                ['plugin' => 'wordpress-seo/wp-seo.php', 'name' => 'Yoast SEO'],
            ],
        ];
    }

    public function siteIndex(WooCommerceStore $store): array
    {
        $this->ensureAvailable($store);

        return ['namespaces' => ['wc/v3', 'yoast/v1']];
    }

    private function ensureAvailable(WooCommerceStore $store): void
    {
        if (! $store->isDemo()) {
            throw new LogicException('The demo transport only serves demo stores.');
        }

        if ($store->simulated_outage) {
            throw $this->httpError(503, 'demo_store_unavailable');
        }
    }

    private function find(WooCommerceStore $store, int $remoteId, bool $lock = false): WooCommerceDemoProduct
    {
        $record = $store->demoProducts()->where('remote_id', $remoteId)->when($lock, fn ($query) => $query->lockForUpdate())->first();

        return $record ?? throw $this->httpError(404, 'woocommerce_rest_product_invalid_id');
    }

    private function httpError(int $status, string $code): WooCommerceException
    {
        return new WooCommerceException(WooCommerceError::fromHttpStatus($status, $code));
    }
}
