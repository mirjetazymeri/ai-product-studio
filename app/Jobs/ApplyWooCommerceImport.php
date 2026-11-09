<?php

namespace App\Jobs;

use App\Models\CatalogTransfer;
use App\Services\WooCommerce\WooCommerceProductImporter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

class ApplyWooCommerceImport implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 900;

    public function __construct(public int $transferId) {}

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("woocommerce-import:{$this->transferId}"))->dontRelease()->expireAfter($this->timeout + 60)];
    }

    public function handle(WooCommerceProductImporter $importer): void
    {
        $importer->apply(CatalogTransfer::query()->findOrFail($this->transferId));
    }

    public function failed(Throwable $exception): void
    {
        $transfer = CatalogTransfer::query()->find($this->transferId);

        if ($transfer !== null) {
            app(WooCommerceProductImporter::class)->fail($transfer, 'The import stopped before finishing. Retrying continues from where it stopped.');
        }
    }
}
