<?php

namespace App\Services\Catalog;

use App\Enums\CatalogTransferStatus;
use App\Enums\ContentLanguage;
use App\Enums\ImportStrategy;
use App\Models\CatalogImportRow;
use App\Models\CatalogTransfer;
use App\Models\Product;
use App\Models\ProductExternalReference;
use App\Models\User;
use App\Services\ProductService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Writes confirmed rows to products. Each row's write and its "done" marker share one
 * transaction, so a retried job continues where it stopped and can't create duplicates.
 */
final class CatalogImporter
{
    public const UPDATABLE_FIELDS = [
        'original_name' => 'Product name',
        'category' => 'Category',
        'material' => 'Material',
        'details' => 'Product details',
        'status' => 'Status',
        'contents_missing' => 'Add translations that don\'t exist yet',
        'contents_existing' => 'Replace existing translations',
    ];

    public function __construct(private readonly ProductService $products) {}

    /**
     * @return bool false if another request already started this import
     */
    public function confirm(CatalogTransfer $transfer, ImportStrategy $strategy, array $updateFields): bool
    {
        $claimed = CatalogTransfer::query()
            ->whereKey($transfer->id)
            ->where('status', CatalogTransferStatus::Validated->value)
            ->update([
                'status' => CatalogTransferStatus::Queued->value,
                'options' => json_encode([...$transfer->options, 'strategy' => $strategy->value, 'update_fields' => $updateFields], JSON_THROW_ON_ERROR),
            ]);

        $transfer->refresh();

        return $claimed === 1;
    }

    public function process(CatalogTransfer $transfer): void
    {
        $transfer->update(['status' => CatalogTransferStatus::Processing]);

        $transfer->rows()
            ->where('status', CatalogImportRow::VALID)
            ->whereNull('result')
            ->chunkById((int) config('catalog.chunk_size'), function ($rows) use ($transfer) {
                foreach ($rows as $row) {
                    $this->processRow($transfer, $row);
                }

                $this->updateCounts($transfer);
            });

        $this->finish($transfer);
    }

    public function markFailed(CatalogTransfer $transfer, Throwable $exception): void
    {
        Log::error('Catalog import failed.', ['transfer_id' => $transfer->id, 'error' => $exception::class]);

        $this->updateCounts($transfer);

        $transfer->update([
            'status' => CatalogTransferStatus::Failed,
            'error_summary' => 'The import stopped before finishing. Nothing was lost, and retrying continues from where it stopped.',
            'completed_at' => now(),
        ]);
    }

    private function processRow(CatalogTransfer $transfer, CatalogImportRow $row): void
    {
        try {
            DB::transaction(function () use ($transfer, $row) {
                $locked = CatalogImportRow::query()->lockForUpdate()->findOrFail($row->id);

                if ($locked->result !== null) {
                    return;
                }

                [$result, $message] = $this->apply($transfer, $locked->payload);

                $locked->update(['result' => $result, 'result_message' => $message]);
            });
        } catch (Throwable $exception) {
            Log::warning('Catalog import row failed.', ['transfer_id' => $transfer->id, 'row' => $row->first_row, 'error' => $exception::class]);

            $row->update(['result' => CatalogImportRow::FAILED, 'result_message' => 'This row couldn\'t be saved.']);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{string, ?string}
     */
    private function apply(CatalogTransfer $transfer, array $payload): array
    {
        $user = $transfer->user;
        $strategy = ImportStrategy::from($transfer->options['strategy']);
        $source = $transfer->options['source'];

        // Matched again here, because products may have changed since the preview.
        [$product, $matchType] = $this->findExisting($user, $source, $payload);

        if ($product === null) {
            $this->create($user, $source, $payload);

            return [CatalogImportRow::CREATED, null];
        }

        return match ($strategy) {
            ImportStrategy::Skip => [CatalogImportRow::SKIPPED, 'This product already exists.'],
            ImportStrategy::Update => $this->update($transfer, $product, $matchType, $source, $payload),
            ImportStrategy::CreateNew => $this->createDespiteMatch($user, $matchType, $source, $payload),
        };
    }

    /**
     * Every lookup is limited to this user's products.
     *
     * @param  array<string, mixed>  $payload
     * @return array{?Product, ?string}
     */
    private function findExisting(User $user, string $source, array $payload): array
    {
        $externalId = $payload['external_id'] ?? null;
        $sku = $payload['product']['sku'] ?? null;

        if (filled($externalId)) {
            $reference = ProductExternalReference::query()
                ->where('user_id', $user->id)->where('source', $source)->where('external_id', $externalId)->first();

            if ($reference !== null) {
                return [$reference->product, 'external'];
            }
        }

        if (filled($sku)) {
            $product = $user->products()->where('sku', $sku)->first();

            if ($product !== null) {
                return [$product, 'sku'];
            }
        }

        if (blank($externalId) && blank($sku)) {
            $product = $user->products()->where('original_name', $payload['product']['original_name'])->first();

            if ($product !== null) {
                return [$product, 'name'];
            }
        }

        return [null, null];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function create(User $user, string $source, array $payload): void
    {
        $attributes = array_filter($payload['product'], fn ($value) => filled($value));
        $attributes['status'] ??= 'draft';

        $product = $this->products->create($user, $attributes, $payload['contents'] ?? []);

        $this->linkExternalId($user, $product, $source, $payload);
    }

    /**
     * A SKU or external ID must stay unique, so only a matching name can be imported as new.
     *
     * @param  array<string, mixed>  $payload
     * @return array{string, ?string}
     */
    private function createDespiteMatch(User $user, ?string $matchType, string $source, array $payload): array
    {
        if ($matchType !== 'name') {
            return [CatalogImportRow::SKIPPED, 'Its SKU or external ID is already in use, so it can\'t be imported as new.'];
        }

        $this->create($user, $source, $payload);

        return [CatalogImportRow::CREATED, 'Created as a new product with the same name as an existing one.'];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{string, ?string}
     */
    private function update(CatalogTransfer $transfer, Product $product, ?string $matchType, string $source, array $payload): array
    {
        if ($matchType === 'name') {
            return [CatalogImportRow::SKIPPED, 'Matched by name only, which isn\'t reliable enough to update.'];
        }

        $fields = $transfer->options['update_fields'] ?? [];

        // A blank cell never erases existing data.
        $attributes = array_filter(
            array_intersect_key($payload['product'], array_flip($fields)),
            fn ($value) => filled($value),
        );

        $contents = $this->contentsToWrite($product, $payload['contents'] ?? [], $fields);

        if ($attributes === [] && $contents === []) {
            return [CatalogImportRow::SKIPPED, 'Nothing in the selected fields to update.'];
        }

        $this->products->update($product, $attributes, $contents);
        $this->linkExternalId($transfer->user, $product, $source, $payload);

        return [CatalogImportRow::UPDATED, null];
    }

    /**
     * @param  array<string, array<string, mixed>>  $contents
     * @param  list<string>  $fields
     * @return array<string, array<string, mixed>>
     */
    private function contentsToWrite(Product $product, array $contents, array $fields): array
    {
        $existing = $product->contents()->pluck('language')
            ->map(fn (ContentLanguage $language) => $language->value)
            ->all();

        return array_filter($contents, function (string $code) use ($existing, $fields) {
            return in_array($code, $existing, true)
                ? in_array('contents_existing', $fields, true)
                : in_array('contents_missing', $fields, true);
        }, ARRAY_FILTER_USE_KEY);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function linkExternalId(User $user, Product $product, string $source, array $payload): void
    {
        if (blank($payload['external_id'] ?? null) || $product->externalReferences()->where('source', $source)->exists()) {
            return;
        }

        $product->externalReferences()->create([
            'user_id' => $user->id,
            'source' => $source,
            'external_id' => $payload['external_id'],
        ]);
    }

    public function updateCounts(CatalogTransfer $transfer): void
    {
        $counts = $transfer->rows()
            ->selectRaw('status, result, SUM(row_count) as source_rows, COUNT(*) as products')
            ->groupBy('status', 'result')
            ->get();

        // Records count source rows, while the summary counts products, which can span several rows.
        $rows = fn (string $result) => (int) $counts->where('result', $result)->sum('source_rows');
        $products = fn (string $result) => (int) $counts->where('result', $result)->sum('products');
        $invalid = (int) $counts->where('status', CatalogImportRow::INVALID)->sum('source_rows');

        $transfer->update([
            'successful_records' => $rows(CatalogImportRow::CREATED) + $rows(CatalogImportRow::UPDATED),
            'failed_records' => $invalid + $rows(CatalogImportRow::FAILED),
            'summary' => [
                ...$transfer->summary,
                'created' => $products(CatalogImportRow::CREATED),
                'updated' => $products(CatalogImportRow::UPDATED),
                'skipped' => $products(CatalogImportRow::SKIPPED),
            ],
        ]);
    }

    private function finish(CatalogTransfer $transfer): void
    {
        $this->updateCounts($transfer);

        $failed = $transfer->failed_records;

        if ($transfer->stored_path !== null) {
            Storage::disk('local')->delete($transfer->stored_path);
        }

        $transfer->update([
            'status' => $failed > 0 ? CatalogTransferStatus::CompletedWithErrors : CatalogTransferStatus::Completed,
            'error_summary' => $failed > 0 ? number_format($failed).' rows couldn\'t be imported.' : null,
            'stored_path' => null,
            'completed_at' => now(),
        ]);
    }
}
