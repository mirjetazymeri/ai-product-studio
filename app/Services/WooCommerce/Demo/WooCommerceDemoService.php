<?php

namespace App\Services\WooCommerce\Demo;

use App\Enums\ContentLanguage;
use App\Models\AiGeneration;
use App\Models\BulkGenerationBatch;
use App\Models\BulkGenerationItem;
use App\Models\Product;
use App\Models\User;
use App\Models\WooCommerceDemoProduct;
use App\Models\WooCommerceStore;
use App\Models\WooCommerceSyncItem;
use App\Services\WooCommerce\Exceptions\WooCommerceException;
use App\Services\WooCommerce\WooCommerceConnectionService;
use App\Services\WooCommerce\WooCommerceProductImporter;
use App\Services\WooCommerce\WooCommerceProductMapper;
use App\Services\WooCommerce\WooCommerceService;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Sets up, changes and resets demo stores. Every action starts from one demo store,
 * so it can't reach a real connection or a real product.
 */
class WooCommerceDemoService
{
    public const STORE_NAME = 'Demo WooCommerce Store';

    private const REMOTE_EDIT_MARKER = 'Edited in the store';

    public function __construct(
        private readonly WooCommerceConnectionService $connections,
        private readonly WooCommerceService $woocommerce,
        private readonly WooCommerceProductMapper $mapper,
    ) {}

    /**
     * Each user has one demo store. The address is unique per user, so a repeated
     * request returns the same store.
     */
    public function activate(User $user): WooCommerceStore
    {
        $store = $user->wooCommerceStores()->createOrFirst(['url' => WooCommerceStore::DEMO_URL], [
            'name' => self::STORE_NAME,
            'connection_type' => WooCommerceStore::TYPE_DEMO,
            'status' => WooCommerceStore::UNTESTED,
            'content_language' => ContentLanguage::English,
        ]);

        if (! $store->demoProducts()->exists()) {
            $this->seedCatalog($store);
            $this->connections->test($store);
        }

        return $store->refresh();
    }

    /**
     * Only rows that belong to this demo store are touched. Real Gemini requests made
     * for demo products stay in history, so resetting can't refund quota.
     */
    public function reset(WooCommerceStore $store): void
    {
        $this->ensureDemo($store);

        DB::transaction(function () use ($store) {
            $productIds = Product::query()
                ->where('user_id', $store->user_id)
                ->where('demo_store_id', $store->id)
                ->pluck('id');

            $batchIds = BulkGenerationItem::query()
                ->where('user_id', $store->user_id)
                ->whereIn('product_id', $productIds)
                ->pluck('bulk_generation_batch_id')
                ->unique();

            AiGeneration::query()
                ->where('user_id', $store->user_id)
                ->where('provider', AiGeneration::SAMPLE_PROVIDER)
                ->whereIn('product_id', $productIds)
                ->delete();

            $store->syncRuns()->delete();

            $store->user->catalogTransfers()
                ->where('file_type', WooCommerceProductImporter::FILE_TYPE)
                ->get()
                ->filter(fn ($transfer) => (int) ($transfer->options['store_id'] ?? 0) === $store->id)
                ->each->delete();

            $store->links()->delete();

            Product::query()->whereKey($productIds)->where('demo_store_id', $store->id)->delete();

            // Removing the products emptied these. A batch with real items is left alone.
            BulkGenerationBatch::query()
                ->whereKey($batchIds)
                ->where('user_id', $store->user_id)
                ->whereDoesntHave('items')
                ->delete();

            $store->demoProducts()->delete();
            $this->seedCatalog($store);

            $store->update([
                'simulated_outage' => false,
                'status' => WooCommerceStore::CONNECTED,
                'last_error_code' => null,
                'content_language' => ContentLanguage::English,
                'last_import_at' => null,
                'last_sync_at' => null,
            ]);
        });
    }

    public function setOutage(WooCommerceStore $store, bool $outage): void
    {
        $this->ensureDemo($store);

        $store->update(['simulated_outage' => $outage]);
    }

    /**
     * Plays the part of someone editing the store after a preview was built.
     *
     * @return string|null label of the field that changed, or null if nothing can change
     *
     * @throws WooCommerceException
     */
    public function simulateRemoteChange(WooCommerceSyncItem $item): ?string
    {
        $store = $item->run->store;

        $this->ensureDemo($store);

        $field = collect($item->fields ?? [])
            ->filter(fn (array $data, string $name) => $name !== 'slug'
                && in_array($name, WooCommerceService::WRITABLE_FIELDS, true)
                && $data['supported'] && $data['changed'])
            ->keys()
            ->first();

        if ($field === null) {
            return null;
        }

        $current = (string) ($this->woocommerce->getProduct($store, $item->remote_id)[$field] ?? '');

        if (! str_contains($current, self::REMOTE_EDIT_MARKER)) {
            $this->woocommerce->updateProduct($store, $item->remote_id, [
                $field => $field === 'name' ? $current.' ('.self::REMOTE_EDIT_MARKER.')' : $current.'<p>'.self::REMOTE_EDIT_MARKER.'.</p>',
            ]);
        }

        return WooCommerceProductMapper::FIELD_LABELS[$field];
    }

    /**
     * Compares a simulated product with its original catalog record. Sync may only ever
     * change descriptive fields, so every line here should stay unchanged.
     *
     * @return list<array{label: string, value: string, unchanged: bool}>
     */
    public function protectedFields(WooCommerceDemoProduct $record): array
    {
        $current = $record->payload;
        $original = DemoCatalog::find($record->remote_id) ?? [];

        $groups = [
            'Price' => [['price', 'regular_price', 'sale_price'], fn () => filled($current['price']) ? '$'.$current['price'] : 'Not set'],
            'Stock quantity' => [['manage_stock', 'stock_quantity', 'stock_status'], fn () => $current['stock_quantity'] === null ? 'Not tracked' : (string) $current['stock_quantity']],
            'SKU' => [['sku'], fn () => $current['sku'] ?: 'None'],
            'Product images' => [['images'], fn () => (string) count($current['images'])],
            'Product status' => [['status'], fn () => ucfirst((string) $current['status'])],
            'Product variations' => [['variations', 'attributes'], fn () => (string) count($current['variations'])],
            'Shipping information' => [['weight', 'dimensions', 'shipping_class'], fn () => ucfirst((string) $current['shipping_class']).', '.$current['weight'].' kg'],
            'Tax information' => [['tax_status', 'tax_class'], fn () => ucfirst((string) $current['tax_status'])],
        ];

        $report = [];

        foreach ($groups as $label => [$keys, $value]) {
            $flip = array_flip($keys);

            $report[] = [
                'label' => $label,
                'value' => $value(),
                'unchanged' => array_intersect_key($current, $flip) == array_intersect_key($original, $flip),
            ];
        }

        return $report;
    }

    /**
     * @return array<string, string> writable fields as plain text, for display
     */
    public function descriptiveFields(WooCommerceDemoProduct $record): array
    {
        $values = [];

        foreach (WooCommerceProductMapper::FIELD_LABELS as $field => $label) {
            $values[$label] = $this->mapper->display($field, (string) ($record->payload[$field] ?? ''));
        }

        return $values;
    }

    private function seedCatalog(WooCommerceStore $store): void
    {
        foreach (DemoCatalog::products() as $product) {
            $store->demoProducts()->create([
                'user_id' => $store->user_id,
                'remote_id' => $product['id'],
                'payload' => $product,
            ]);
        }
    }

    private function ensureDemo(WooCommerceStore $store): void
    {
        if (! $store->isDemo()) {
            throw new LogicException('Only demo stores can be changed by the demo service.');
        }
    }
}
