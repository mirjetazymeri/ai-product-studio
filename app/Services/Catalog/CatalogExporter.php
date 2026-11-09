<?php

namespace App\Services\Catalog;

use App\Enums\BulkItemStatus;
use App\Enums\CatalogTransferStatus;
use App\Enums\ExportContentScope;
use App\Models\BulkGenerationItem;
use App\Models\CatalogTransfer;
use App\Models\User;
use App\Services\Catalog\Export\CatalogExportFormat;
use App\Services\Catalog\Export\SpreadsheetWriter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

final class CatalogExporter
{
    private const CHUNK = 200;

    public function __construct(
        private readonly ProductSelection $selection,
        private readonly CatalogExportFormat $format,
        private readonly SpreadsheetWriter $writer,
    ) {}

    /**
     * @param  array<string, mixed>  $selection  product_ids, all_matching, search, status
     * @param  list<string>  $languages
     */
    public function createTransfer(User $user, string $fileType, ExportContentScope $scope, array $languages, array $selection): CatalogTransfer
    {
        return $user->catalogTransfers()->create([
            'operation' => CatalogTransfer::EXPORT,
            'file_type' => $fileType,
            'status' => CatalogTransferStatus::Queued,
            'total_records' => $this->selection->query($user, $selection)->count(),
            'options' => ['scope' => $scope->value, 'languages' => $languages, 'selection' => $selection],
            'expires_at' => now()->addDays((int) config('catalog.retention_days')),
        ]);
    }

    public function generate(CatalogTransfer $transfer): void
    {
        $transfer->update(['status' => CatalogTransferStatus::Processing]);

        $disk = Storage::disk('local');
        $relative = "catalog/exports/{$transfer->user_id}/".Str::uuid().'.'.$transfer->file_type;

        try {
            $disk->makeDirectory(dirname($relative));

            $count = $this->writeFile($transfer, $disk->path($relative));

            $transfer->update([
                'status' => CatalogTransferStatus::Completed,
                'stored_path' => $relative,
                'original_name' => 'products-export-'.now()->format('Y-m-d-His').'.'.$transfer->file_type,
                'successful_records' => $count,
                'completed_at' => now(),
            ]);
        } catch (Throwable $exception) {
            Log::error('Catalog export failed.', ['transfer_id' => $transfer->id, 'error' => $exception::class]);

            $disk->delete($relative);

            $transfer->update([
                'status' => CatalogTransferStatus::Failed,
                'error_summary' => 'The export couldn\'t be created. Please try again.',
                'completed_at' => now(),
            ]);
        }
    }

    private function writeFile(CatalogTransfer $transfer, string $path): int
    {
        $scope = ExportContentScope::from($transfer->options['scope']);
        $languages = $transfer->options['languages'];
        $count = 0;

        $this->writer->open($path, $transfer->file_type);
        $this->writer->addHeader($this->format->headers($scope));

        $this->selection->query($transfer->user, $transfer->options['selection'])
            ->with(['contents', 'externalReferences'])
            ->chunkById(self::CHUNK, function (Collection $products) use ($transfer, $scope, $languages, &$count) {
                $drafts = $scope->includesDrafts() ? $this->drafts($transfer->user, $products->modelKeys()) : [];

                foreach ($products as $product) {
                    foreach ($this->format->rows($product, $scope, $languages, $drafts[$product->id] ?? []) as $row) {
                        $this->writer->addRow($row);
                    }

                    $count++;
                }
            });

        $this->writer->close();

        return $count;
    }

    /**
     * The newest draft waiting for review, per product and language.
     *
     * @param  list<int>  $productIds
     * @return array<int, array<string, array<string, mixed>>>
     */
    private function drafts(User $user, array $productIds): array
    {
        $items = $user->bulkGenerationItems()
            ->with('generation:id,generated_content')
            ->whereIn('product_id', $productIds)
            ->where('status', BulkItemStatus::Generated->value)
            ->where('review_status', BulkGenerationItem::REVIEW_PENDING)
            ->orderBy('id')
            ->get();

        $drafts = [];

        foreach ($items as $item) {
            $drafts[$item->product_id][$item->language->value] = $item->reviewableContent();
        }

        return $drafts;
    }
}
