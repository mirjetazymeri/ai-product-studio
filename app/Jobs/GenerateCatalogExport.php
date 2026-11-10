<?php

namespace App\Jobs;

use App\Enums\CatalogTransferStatus;
use App\Models\CatalogTransfer;
use App\Services\Catalog\CatalogExporter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class GenerateCatalogExport implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public int $transferId) {}

    public function handle(CatalogExporter $exporter): void
    {
        $exporter->generate(CatalogTransfer::query()->findOrFail($this->transferId));
    }

    /**
     * A worker that dies mid-export must not leave it "processing" forever.
     */
    public function failed(Throwable $exception): void
    {
        CatalogTransfer::query()
            ->whereKey($this->transferId)
            ->whereIn('status', [CatalogTransferStatus::Queued->value, CatalogTransferStatus::Processing->value])
            ->update([
                'status' => CatalogTransferStatus::Failed->value,
                'error_summary' => 'The export couldn\'t be created. Please try again.',
                'completed_at' => now(),
            ]);
    }
}
