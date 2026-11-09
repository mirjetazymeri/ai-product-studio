<?php

namespace App\Services\WooCommerce;

use App\Enums\ContentLanguage;
use App\Enums\WooCommerceError;
use App\Models\User;
use App\Models\WooCommerceStore;
use App\Models\WooCommerceSyncItem;
use App\Models\WooCommerceSyncRun;
use App\Services\WooCommerce\Capabilities\CapabilityDetector;
use App\Services\WooCommerce\Exceptions\WooCommerceException;
use Illuminate\Support\Facades\DB;

class WooCommerceConnectionService
{
    public function __construct(
        private readonly WooCommerceService $woocommerce,
        private readonly CapabilityDetector $capabilities,
    ) {}

    /**
     * Read-only: lists one product and inspects the site. Nothing is changed in the store.
     */
    public function test(WooCommerceStore $store): ?WooCommerceException
    {
        try {
            $sample = $this->woocommerce->listProducts($store, 1, 1)['products'][0] ?? null;
            $capabilities = $this->capabilities->detect($store, is_array($sample) ? $sample : null);
        } catch (WooCommerceException $exception) {
            $store->update([
                'status' => WooCommerceStore::FAILING,
                'last_error_code' => $exception->error->value,
                'last_checked_at' => now(),
            ]);

            return $exception;
        }

        $store->update([
            'status' => WooCommerceStore::CONNECTED,
            'last_error_code' => null,
            'capabilities' => $capabilities,
            'content_language' => ContentLanguage::tryFrom((string) ($capabilities['language']['default'] ?? '')) ?? $store->content_language,
            'last_checked_at' => now(),
        ]);

        return null;
    }

    public function isDuplicate(User $user, string $url, ?int $ignoreId = null): bool
    {
        return $user->wooCommerceStores()
            ->where('url', $url)
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();
    }

    /**
     * Removes local credentials and stops future work. Remote products and API keys
     * are left alone, and linked local products and history are kept.
     */
    public function disconnect(WooCommerceStore $store): void
    {
        DB::transaction(function () use ($store) {
            $store->update([
                'consumer_key' => null,
                'consumer_secret' => null,
                'status' => WooCommerceStore::DISCONNECTED,
                'last_error_code' => null,
                'disconnected_at' => now(),
            ]);

            $runs = $store->syncRuns()->whereIn('status', [...WooCommerceSyncRun::ACTIVE, WooCommerceSyncRun::READY])->get();

            foreach ($runs as $run) {
                $run->items()
                    ->whereIn('status', [WooCommerceSyncItem::PENDING, WooCommerceSyncItem::READY, WooCommerceSyncItem::QUEUED])
                    ->update([
                        'status' => WooCommerceSyncItem::FAILED,
                        'error_code' => WooCommerceError::Disconnected->value,
                        'message' => WooCommerceError::Disconnected->message(),
                    ]);

                $run->update([
                    'status' => WooCommerceSyncRun::FAILED,
                    'error_summary' => WooCommerceError::Disconnected->message(),
                    'completed_at' => now(),
                ]);
            }
        });
    }
}
