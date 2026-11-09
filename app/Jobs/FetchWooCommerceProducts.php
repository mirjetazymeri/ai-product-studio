<?php

namespace App\Jobs;

use App\Models\CatalogTransfer;
use App\Services\WooCommerce\WooCommerceProductImporter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Reads one page of a store's products, then queues itself for the next page.
 */
class FetchWooCommerceProducts implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public int $transferId) {}

    public function handle(WooCommerceProductImporter $importer): void
    {
        $delay = $importer->fetchNextPage(CatalogTransfer::query()->findOrFail($this->transferId));

        if ($delay === null || ($delay > 0 && config('queue.default') === 'sync')) {
            return;
        }

        self::dispatch($this->transferId)->delay($delay);
    }

    public function failed(Throwable $exception): void
    {
        $transfer = CatalogTransfer::query()->find($this->transferId);

        if ($transfer !== null) {
            app(WooCommerceProductImporter::class)->fail($transfer, 'The import stopped before finishing. Retrying continues from where it stopped.');
        }
    }
}
