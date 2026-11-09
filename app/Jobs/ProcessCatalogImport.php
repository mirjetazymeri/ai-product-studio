<?php

namespace App\Jobs;

use App\Models\CatalogTransfer;
use App\Services\Catalog\CatalogImporter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

class ProcessCatalogImport implements ShouldQueue
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
        return [(new WithoutOverlapping("catalog-import:{$this->transferId}"))->dontRelease()->expireAfter($this->timeout + 60)];
    }

    public function handle(CatalogImporter $importer): void
    {
        $importer->process(CatalogTransfer::query()->findOrFail($this->transferId));
    }

    public function failed(Throwable $exception): void
    {
        $transfer = CatalogTransfer::query()->find($this->transferId);

        if ($transfer !== null) {
            app(CatalogImporter::class)->markFailed($transfer, $exception);
        }
    }
}
