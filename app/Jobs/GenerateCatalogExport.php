<?php

namespace App\Jobs;

use App\Models\CatalogTransfer;
use App\Services\Catalog\CatalogExporter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

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
}
