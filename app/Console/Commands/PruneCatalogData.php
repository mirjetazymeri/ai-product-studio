<?php

namespace App\Console\Commands;

use App\Models\BulkGenerationBatch;
use App\Models\CatalogTransfer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

#[Signature('catalog:prune')]
#[Description('Remove expired import and export files and staged rows, keeping the history records')]
class PruneCatalogData extends Command
{
    public function handle(): int
    {
        $removed = 0;

        CatalogTransfer::query()->where('expires_at', '<', now())->chunkById(200, function ($transfers) use (&$removed) {
            foreach ($transfers as $transfer) {
                if ($transfer->status->isRunning()) {
                    continue;
                }

                if ($transfer->stored_path !== null) {
                    Storage::disk('local')->delete($transfer->stored_path);
                    $transfer->update(['stored_path' => null]);
                    $removed++;
                }

                $transfer->rows()->delete();
            }
        });

        BulkGenerationBatch::query()
            ->whereNotNull('custom_instructions')
            ->where('status', '!=', BulkGenerationBatch::RUNNING)
            ->where('updated_at', '<', now()->subDays((int) config('catalog.retention_days')))
            ->update(['custom_instructions' => null]);

        $this->info("Removed {$removed} expired files.");

        return self::SUCCESS;
    }
}
