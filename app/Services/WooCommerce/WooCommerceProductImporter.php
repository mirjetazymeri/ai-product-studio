<?php

namespace App\Services\WooCommerce;

use App\Enums\CatalogTransferStatus;
use App\Enums\ContentLanguage;
use App\Enums\ContentSource;
use App\Enums\WooCommerceError;
use App\Models\CatalogImportRow;
use App\Models\CatalogTransfer;
use App\Models\Product;
use App\Models\User;
use App\Models\WooCommerceProductLink;
use App\Models\WooCommerceStore;
use App\Services\Catalog\CatalogImporter;
use App\Services\ProductService;
use App\Services\WooCommerce\Exceptions\WooCommerceException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Brings store products in using the same staging table and transfer history as file
 * imports. It only ever reads from the store, and nothing is written locally until
 * the user has reviewed the results.
 */
class WooCommerceProductImporter
{
    public const FILE_TYPE = 'woo';

    public const LINKED = 'linked';

    public const SKU_MATCH = 'sku';

    public const AMBIGUOUS = 'ambiguous';

    private const STALLED_AFTER_MINUTES = 15;

    public const REFRESHABLE_FIELDS = [
        'original_name' => 'Product name',
        'category' => 'Category',
        'details' => 'Details',
        'status' => 'Status',
        'imported_content' => 'Content still unchanged since it was imported',
    ];

    public function __construct(
        private readonly WooCommerceService $woocommerce,
        private readonly WooCommerceProductMapper $mapper,
        private readonly ProductService $products,
        private readonly CatalogImporter $catalogImporter,
    ) {}

    /**
     * A running import blocks a new one, unless it has clearly stalled.
     */
    public function activeImportFor(WooCommerceStore $store): ?CatalogTransfer
    {
        return $this->importsFor($store, [CatalogTransferStatus::Queued, CatalogTransferStatus::Processing])
            ->first(fn (CatalogTransfer $transfer) => $transfer->updated_at->gt(now()->subMinutes(self::STALLED_AFTER_MINUTES)));
    }

    public function start(WooCommerceStore $store, ContentLanguage $language): CatalogTransfer
    {
        // A new read makes any review still waiting out of date.
        foreach ($this->importsFor($store, [CatalogTransferStatus::Validated]) as $outdated) {
            $this->fail($outdated, 'Replaced by a newer import from this store.');
        }

        $store->update(['content_language' => $language]);

        return $store->user->catalogTransfers()->create([
            'operation' => CatalogTransfer::IMPORT,
            'file_type' => self::FILE_TYPE,
            'original_name' => $store->name,
            'status' => CatalogTransferStatus::Processing,
            'options' => ['store_id' => $store->id, 'phase' => 'fetching', 'next_page' => 1, 'language' => $language->value],
            'summary' => [],
            'expires_at' => now()->addDays((int) config('catalog.retention_days')),
        ]);
    }

    /**
     * Stages one page. Repeated pages are harmless, because each remote ID is stored once.
     *
     * @return int|null seconds to wait before the next page, or null when finished
     */
    public function fetchNextPage(CatalogTransfer $transfer): ?int
    {
        $store = $this->storeFor($transfer);
        $page = (int) $transfer->options['next_page'];

        try {
            $result = $this->woocommerce->listProducts($store, $page, (int) config('woocommerce.per_page'));
        } catch (WooCommerceException $exception) {
            if ($exception->error === WooCommerceError::RateLimited) {
                return $exception->retryAfter ?? 30;
            }

            $this->fail($transfer, $exception->error->message());

            return null;
        }

        $this->stage($transfer, $store, $result['products'], $page);

        if ($result['products'] === [] || $page >= $result['total_pages']) {
            $this->finishFetching($transfer);

            return null;
        }

        $transfer->update(['options' => [...$transfer->options, 'next_page' => $page + 1]]);

        return 0;
    }

    /**
     * @param  array<int, string>  $decisions  link, new or skip, by staged row ID
     * @param  array<string, mixed>  $options  import_new, refresh_linked, refresh_fields
     * @return bool false if another request already started this import
     */
    public function confirm(CatalogTransfer $transfer, array $decisions, array $options): bool
    {
        return DB::transaction(function () use ($transfer, $decisions, $options) {
            $claimed = CatalogTransfer::query()
                ->whereKey($transfer->id)
                ->where('status', CatalogTransferStatus::Validated->value)
                ->update([
                    'status' => CatalogTransferStatus::Queued->value,
                    'options' => json_encode([...$transfer->options, ...$options, 'phase' => 'applying'], JSON_THROW_ON_ERROR),
                ]);

            if ($claimed !== 1) {
                return false;
            }

            $rows = $transfer->rows()->whereIn('match_type', [self::SKU_MATCH, self::AMBIGUOUS])->whereKey(array_keys($decisions))->get();

            foreach ($rows as $row) {
                $row->update(['payload' => [...$row->payload, 'decision' => $decisions[$row->id]]]);
            }

            $transfer->refresh();

            return true;
        });
    }

    public function apply(CatalogTransfer $transfer): void
    {
        $transfer->update(['status' => CatalogTransferStatus::Processing]);

        $store = $this->storeFor($transfer);

        $transfer->rows()
            ->where('status', CatalogImportRow::VALID)
            ->whereNull('result')
            ->chunkById((int) config('catalog.chunk_size'), function (Collection $rows) use ($transfer, $store) {
                foreach ($rows as $row) {
                    $this->applyRow($transfer, $store, $row);
                }

                $this->catalogImporter->updateCounts($transfer);
            });

        $this->catalogImporter->updateCounts($transfer);

        $failed = $transfer->failed_records;

        $transfer->update([
            'status' => $failed > 0 ? CatalogTransferStatus::CompletedWithErrors : CatalogTransferStatus::Completed,
            'error_summary' => $failed > 0 ? number_format($failed).' products couldn\'t be imported.' : null,
            'completed_at' => now(),
        ]);

        $store->update(['last_import_at' => now()]);
    }

    public function fail(CatalogTransfer $transfer, string $message): void
    {
        $transfer->update([
            'status' => CatalogTransferStatus::Failed,
            'error_summary' => $message,
            'completed_at' => now(),
        ]);
    }

    /**
     * @param  list<CatalogTransferStatus>  $statuses
     * @return Collection<int, CatalogTransfer>
     */
    private function importsFor(WooCommerceStore $store, array $statuses): Collection
    {
        return $store->user->catalogTransfers()
            ->where('file_type', self::FILE_TYPE)
            ->whereIn('status', array_map(fn (CatalogTransferStatus $status) => $status->value, $statuses))
            ->get()
            ->filter(fn (CatalogTransfer $transfer) => (int) $transfer->options['store_id'] === $store->id);
    }

    public function storeFor(CatalogTransfer $transfer): WooCommerceStore
    {
        return WooCommerceStore::query()->where('user_id', $transfer->user_id)->findOrFail($transfer->options['store_id']);
    }

    /**
     * @param  list<mixed>  $products
     */
    private function stage(CatalogTransfer $transfer, WooCommerceStore $store, array $products, int $page): void
    {
        $staged = [];
        $rows = [];
        $position = ($page - 1) * (int) config('woocommerce.per_page');

        foreach ($products as $index => $remote) {
            $mapped = is_array($remote) ? $this->mapper->toStaged($remote) : null;

            if ($mapped === null) {
                $rows[] = $this->invalidRow($transfer, $position + $index + 1);

                continue;
            }

            $staged[$position + $index + 1] = $mapped;
        }

        $links = $store->links()->with('product')->whereIn('remote_id', array_column($staged, 'id'))->get()->keyBy('remote_id');
        // Sample products must never be offered as a match for real ones.
        $skuMatches = $store->isDemo() ? collect() : $this->productsBySku($transfer->user, array_values(array_filter(array_column($staged, 'sku'))));
        $linkedProductIds = $store->links()->whereIn('product_id', $skuMatches->pluck('id'))->pluck('product_id')->flip();

        foreach ($staged as $position => $remote) {
            $rows[] = $this->stagedRow($transfer, $position, $remote, $links->get($remote['id']), $remote['sku'] === null ? null : $skuMatches->get(mb_strtolower($remote['sku'])), $linkedProductIds);
        }

        CatalogImportRow::insertOrIgnore($rows);
    }

    /**
     * @param  list<string>  $skus
     * @return Collection<string, Product>
     */
    private function productsBySku(User $user, array $skus): Collection
    {
        return $user->products()->whereIn('sku', $skus)->get()->keyBy(fn (Product $product) => mb_strtolower((string) $product->sku));
    }

    /**
     * @param  array<string, mixed>  $remote
     * @param  Collection<int, mixed>  $linkedProductIds
     * @return array<string, mixed>
     */
    private function stagedRow(CatalogTransfer $transfer, int $position, array $remote, ?WooCommerceProductLink $link, ?Product $skuMatch, Collection $linkedProductIds): array
    {
        $matchType = null;
        $localId = null;
        $changes = [];

        if ($link !== null) {
            $matchType = self::LINKED;
            $localId = $link->product_id;
            $changes = $this->mapper->changedLocalFields($link->product, $remote);
        } elseif ($skuMatch !== null) {
            // A local product already tied to another remote product can't be merged.
            $matchType = $linkedProductIds->has($skuMatch->id) ? self::AMBIGUOUS : self::SKU_MATCH;
            $localId = $skuMatch->id;
        }

        return [
            'catalog_transfer_id' => $transfer->id,
            'external_key' => 'remote:'.$remote['id'],
            'first_row' => $position,
            'row_count' => 1,
            'status' => CatalogImportRow::VALID,
            'payload' => json_encode(['remote' => $remote, 'local_product_id' => $localId, 'changes' => $changes, 'product' => ['original_name' => $remote['name']]], JSON_THROW_ON_ERROR),
            'errors' => null,
            'match_type' => $matchType,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function invalidRow(CatalogTransfer $transfer, int $position): array
    {
        return [
            'catalog_transfer_id' => $transfer->id,
            'external_key' => null,
            'first_row' => $position,
            'row_count' => 1,
            'status' => CatalogImportRow::INVALID,
            'payload' => json_encode(['product' => ['original_name' => null]], JSON_THROW_ON_ERROR),
            'errors' => json_encode(['The store returned this product without a valid ID or name.'], JSON_THROW_ON_ERROR),
            'match_type' => null,
        ];
    }

    private function finishFetching(CatalogTransfer $transfer): void
    {
        $this->markDuplicateSkusAmbiguous($transfer);

        $counts = $transfer->rows()->selectRaw('status, match_type, COUNT(*) as total')->groupBy('status', 'match_type')->get();
        $count = fn (?string $type) => (int) $counts->where('status', CatalogImportRow::VALID)->where('match_type', $type)->sum('total');
        $valid = (int) $counts->where('status', CatalogImportRow::VALID)->sum('total');
        $invalid = (int) $counts->where('status', CatalogImportRow::INVALID)->sum('total');

        $transfer->update([
            'status' => CatalogTransferStatus::Validated,
            'total_records' => $valid + $invalid,
            'summary' => [
                'total_rows' => $valid + $invalid,
                'valid_rows' => $valid,
                'invalid_rows' => $invalid,
                'products' => $valid,
                'possible_duplicates' => $count(self::SKU_MATCH) + $count(self::AMBIGUOUS),
                'new' => $count(null),
                'linked' => $count(self::LINKED),
            ],
        ]);
    }

    /**
     * Two remote products sharing a SKU can't be told apart, so neither is matched.
     */
    private function markDuplicateSkusAmbiguous(CatalogTransfer $transfer): void
    {
        $idsBySku = [];

        $transfer->rows()->where('status', CatalogImportRow::VALID)->where(fn ($query) => $query->whereNull('match_type')->orWhere('match_type', self::SKU_MATCH))
            ->chunkById(500, function (Collection $rows) use (&$idsBySku) {
                foreach ($rows as $row) {
                    $sku = $row->payload['remote']['sku'] ?? null;

                    if ($sku !== null) {
                        $idsBySku[mb_strtolower($sku)][] = $row->id;
                    }
                }
            });

        $duplicates = array_merge(...array_values(array_filter($idsBySku, fn (array $ids) => count($ids) > 1)) ?: [[]]);

        foreach (array_chunk($duplicates, 500) as $ids) {
            $transfer->rows()->whereKey($ids)->update(['match_type' => self::AMBIGUOUS]);
        }
    }

    private function applyRow(CatalogTransfer $transfer, WooCommerceStore $store, CatalogImportRow $row): void
    {
        try {
            DB::transaction(function () use ($transfer, $store, $row) {
                $locked = CatalogImportRow::query()->lockForUpdate()->findOrFail($row->id);

                if ($locked->result !== null) {
                    return;
                }

                [$result, $message] = $this->applyPayload($transfer, $store, $locked);

                $locked->update(['result' => $result, 'result_message' => $message]);
            });
        } catch (Throwable $exception) {
            Log::warning('WooCommerce import row failed.', ['transfer_id' => $transfer->id, 'row_id' => $row->id, 'error' => $exception::class]);

            $row->update(['result' => CatalogImportRow::FAILED, 'result_message' => 'This product couldn\'t be saved.']);
        }
    }

    /**
     * @return array{string, ?string}
     */
    private function applyPayload(CatalogTransfer $transfer, WooCommerceStore $store, CatalogImportRow $row): array
    {
        $remote = $row->payload['remote'];

        // Checked again here, so a repeated job can never create a second product.
        $link = $store->links()->with('product')->where('remote_id', $remote['id'])->first();

        if ($link !== null) {
            return ($transfer->options['refresh_linked'] ?? false)
                ? $this->refresh($transfer, $store, $link, $remote)
                : [CatalogImportRow::SKIPPED, 'Already linked.'];
        }

        $decision = $row->payload['decision'] ?? 'skip';

        return match ($row->match_type) {
            self::SKU_MATCH => match ($decision) {
                'link' => $this->linkToExisting($transfer, $store, (int) $row->payload['local_product_id'], $remote),
                'new' => $this->createNew($transfer, $store, $remote),
                default => [CatalogImportRow::SKIPPED, 'A possible match wasn\'t resolved, so nothing was changed.'],
            },
            self::AMBIGUOUS => $decision === 'new'
                ? $this->createNew($transfer, $store, $remote)
                : [CatalogImportRow::SKIPPED, 'This SKU is ambiguous, so nothing was merged.'],
            default => ($transfer->options['import_new'] ?? false)
                ? $this->createNew($transfer, $store, $remote)
                : [CatalogImportRow::SKIPPED, 'New products weren\'t selected for import.'],
        };
    }

    /**
     * @param  array<string, mixed>  $remote
     * @return array{string, ?string}
     */
    private function createNew(CatalogTransfer $transfer, WooCommerceStore $store, array $remote): array
    {
        $user = $store->user;

        // Local SKUs are unique per user. The store's own SKU is always kept on the link,
        // and demo products have no local SKU, so they can't take one from the real catalog.
        $sku = ! $store->isDemo() && $remote['sku'] !== null && ! $user->products()->where('sku', $remote['sku'])->exists() && mb_strlen($remote['sku']) <= 100 ? $remote['sku'] : null;

        $product = $this->products->create($user, [...$this->mapper->localAttributes($remote), 'sku' => $sku]);

        if ($store->isDemo()) {
            $product->forceFill(['demo_store_id' => $store->id])->save();
        }

        $this->createLink($store, $product, $remote);

        $language = ContentLanguage::tryFrom((string) ($transfer->options['language'] ?? ''));
        $content = $this->mapper->localContent($remote);

        if ($language !== null && $content !== []) {
            $this->products->saveContent($product, $language, $content, ContentSource::WooCommerce);
        }

        return [CatalogImportRow::CREATED, null];
    }

    /**
     * @param  array<string, mixed>  $remote
     * @return array{string, ?string}
     */
    private function linkToExisting(CatalogTransfer $transfer, WooCommerceStore $store, int $productId, array $remote): array
    {
        $product = $store->user->products()->find($productId);

        if ($product === null || $store->links()->where('product_id', $product->id)->exists()) {
            return [CatalogImportRow::SKIPPED, 'That local product is no longer available to link.'];
        }

        // Linking records the relationship only. Local fields and content are left alone.
        $this->createLink($store, $product, $remote);

        return [CatalogImportRow::UPDATED, 'Linked to the existing local product.'];
    }

    /**
     * @param  array<string, mixed>  $remote
     */
    private function createLink(WooCommerceStore $store, Product $product, array $remote): void
    {
        $store->links()->create(['user_id' => $store->user_id, 'product_id' => $product->id, ...$this->linkAttributes($remote)]);

        $product->externalReferences()->create([
            'user_id' => $store->user_id,
            'source' => $store->externalReferenceSource(),
            'external_id' => (string) $remote['id'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $remote
     * @return array<string, mixed>
     */
    private function linkAttributes(array $remote): array
    {
        return [
            'remote_id' => $remote['id'],
            'remote_type' => mb_substr($remote['type'], 0, 30),
            'sync_mode' => $this->mapper->syncModeFor($remote['type']),
            'remote_sku' => $remote['sku'] === null ? null : mb_substr($remote['sku'], 0, 255),
            'remote_status' => $remote['status'] === null ? null : mb_substr($remote['status'], 0, 30),
            'remote_modified_gmt' => $remote['modified_gmt'],
            'remote_language' => $remote['language'],
            'permalink' => $remote['permalink'],
            'translations' => $remote['translations'],
            'metadata' => ['variation_count' => $remote['variation_count'], 'categories' => $remote['categories']],
            'last_imported_at' => now(),
        ];
    }

    /**
     * Only the approved fields change. Content written or generated here is never touched.
     *
     * @param  array<string, mixed>  $remote
     * @return array{string, ?string}
     */
    private function refresh(CatalogTransfer $transfer, WooCommerceStore $store, WooCommerceProductLink $link, array $remote): array
    {
        $fields = $transfer->options['refresh_fields'] ?? [];

        $attributes = array_intersect_key($this->mapper->localAttributes($remote), array_flip($fields));

        if ($attributes !== []) {
            $this->products->update($link->product, $attributes);
        }

        $link->update($this->linkAttributes($remote));

        $language = ContentLanguage::tryFrom((string) ($transfer->options['language'] ?? ''));

        if (in_array('imported_content', $fields, true) && $language !== null) {
            $this->refreshImportedContent($link->product, $language, $remote);
        }

        return [CatalogImportRow::UPDATED, null];
    }

    /**
     * @param  array<string, mixed>  $remote
     */
    private function refreshImportedContent(Product $product, ContentLanguage $language, array $remote): void
    {
        $existing = $product->contents()->where('language', $language->value)->first();
        $content = $this->mapper->localContent($remote);

        if ($content !== [] && ($existing === null || $existing->source === ContentSource::WooCommerce)) {
            $this->products->saveContent($product, $language, $content, ContentSource::WooCommerce);
        }
    }
}
