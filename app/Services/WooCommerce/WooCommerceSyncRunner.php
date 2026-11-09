<?php

namespace App\Services\WooCommerce;

use App\Jobs\ProcessWooCommerceSyncRun;
use App\Models\WooCommerceSyncItem;
use App\Models\WooCommerceSyncRun;

/**
 * Small runs finish during the request. Larger ones, or ones that must wait for a
 * rate limit, continue on the queue.
 */
class WooCommerceSyncRunner
{
    public function __construct(
        private readonly WooCommerceSyncPreviewService $preview,
        private readonly WooCommerceSyncService $sync,
    ) {}

    public function next(WooCommerceSyncRun $run): ?int
    {
        return $run->status === WooCommerceSyncRun::PREVIEWING
            ? $this->preview->processNext($run)
            : $this->sync->processNext($run);
    }

    public function start(WooCommerceSyncRun $run): void
    {
        $open = $run->items()->whereIn('status', [WooCommerceSyncItem::PENDING, WooCommerceSyncItem::QUEUED])->count();

        // A demo store does no network work, so it never needs a queue worker.
        if ($open > config('woocommerce.sync_inline_items') && ! $run->store->isDemo()) {
            ProcessWooCommerceSyncRun::dispatch($run->id);

            return;
        }

        do {
            $delay = $this->next($run->refresh());
        } while ($delay === 0);

        if ($delay !== null) {
            ProcessWooCommerceSyncRun::dispatch($run->id)->delay($delay);
        }
    }
}
