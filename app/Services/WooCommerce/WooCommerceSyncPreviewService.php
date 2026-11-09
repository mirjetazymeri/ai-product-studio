<?php

namespace App\Services\WooCommerce;

use App\Enums\ContentLanguage;
use App\Enums\WooCommerceError;
use App\Models\User;
use App\Models\WooCommerceProductLink;
use App\Models\WooCommerceStore;
use App\Models\WooCommerceSyncItem;
use App\Models\WooCommerceSyncRun;
use App\Services\WooCommerce\Exceptions\WooCommerceException;
use App\Services\WooCommerce\Languages\LanguageAdapter;
use App\Services\WooCommerce\Languages\LanguageAdapterFactory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Builds the field-level comparison by reading the live store. It never writes to it.
 */
class WooCommerceSyncPreviewService
{
    public function __construct(
        private readonly WooCommerceService $woocommerce,
        private readonly WooCommerceProductMapper $mapper,
        private readonly LanguageAdapterFactory $languages,
    ) {}

    /**
     * @param  Collection<int, WooCommerceProductLink>  $links
     */
    public function createRun(User $user, WooCommerceStore $store, Collection $links, ContentLanguage $language): WooCommerceSyncRun
    {
        return DB::transaction(function () use ($user, $store, $links, $language) {
            $run = $user->wooCommerceSyncRuns()->create([
                'woocommerce_store_id' => $store->id,
                'language' => $language,
                'status' => WooCommerceSyncRun::PREVIEWING,
            ]);

            foreach ($links as $link) {
                $run->items()->create([
                    'woocommerce_product_link_id' => $link->id,
                    'product_id' => $link->product_id,
                    'remote_id' => $link->remote_id,
                    'product_name' => $link->product->original_name,
                    'language' => $language,
                    'status' => WooCommerceSyncItem::PENDING,
                ]);
            }

            return $run;
        });
    }

    /**
     * A rollback is an ordinary preview whose proposed values are the recorded old ones.
     */
    public function createRollback(WooCommerceSyncRun $source): ?WooCommerceSyncRun
    {
        $items = $source->items()->whereNotNull('previous_values')->whereNotNull('applied_values')->get()
            ->filter(fn (WooCommerceSyncItem $item) => $this->writtenFields($item) !== []);

        if ($items->isEmpty()) {
            return null;
        }

        return DB::transaction(function () use ($source, $items) {
            $run = $source->user->wooCommerceSyncRuns()->create([
                'woocommerce_store_id' => $source->woocommerce_store_id,
                'rollback_of_id' => $source->id,
                'language' => $source->language,
                'status' => WooCommerceSyncRun::PREVIEWING,
            ]);

            foreach ($items as $item) {
                $run->items()->create([
                    'woocommerce_product_link_id' => $item->woocommerce_product_link_id,
                    'product_id' => $item->product_id,
                    'rollback_of_item_id' => $item->id,
                    'remote_id' => $item->remote_id,
                    'product_name' => $item->product_name,
                    'language' => $source->language,
                    'status' => WooCommerceSyncItem::PENDING,
                ]);
            }

            return $run;
        });
    }

    /**
     * @return int|null seconds until the next item may run, or null when the preview is built
     */
    public function processNext(WooCommerceSyncRun $run): ?int
    {
        $item = $run->items()->where('status', WooCommerceSyncItem::PENDING)->orderBy('id')->first();

        if ($item === null) {
            $this->finish($run);

            return null;
        }

        try {
            $this->buildItem($run, $item);
        } catch (WooCommerceException $exception) {
            if ($exception->error === WooCommerceError::RateLimited) {
                return $exception->retryAfter ?? 30;
            }

            $item->update([
                'status' => WooCommerceSyncItem::FAILED,
                'error_code' => $exception->error->value,
                'message' => $exception->error->message(),
            ]);
        }

        return 0;
    }

    private function buildItem(WooCommerceSyncRun $run, WooCommerceSyncItem $item): void
    {
        $item->rollback_of_item_id === null
            ? $this->buildSyncItem($run, $item)
            : $this->buildRollbackItem($item);
    }

    private function buildSyncItem(WooCommerceSyncRun $run, WooCommerceSyncItem $item): void
    {
        $link = $item->link;

        if ($link === null || $item->product === null) {
            $this->skip($item, 'This product is no longer linked.');

            return;
        }

        if ($link->isReadOnly()) {
            $this->skip($item, "Products of type \"{$link->remote_type}\" are read-only for now.");

            return;
        }

        $adapter = $this->languages->for($run->store);

        if (($reason = $adapter->unavailableReason()) !== null) {
            $this->skip($item, $reason);

            return;
        }

        if (! $this->resolveRemoteId($item, $adapter, $link, $run->language)) {
            return;
        }

        $content = $item->product->contents()->where('language', $run->language->value)->first();

        if ($content === null || ! $content->source->isApprovedForSync()) {
            $this->skip($item, "There is no approved {$run->language->label()} content for this product.");

            return;
        }

        $remote = $this->woocommerce->getProduct($run->store, $item->remote_id);

        if ($this->mapper->syncModeFor((string) ($remote['type'] ?? '')) === WooCommerceProductLink::MODE_READ_ONLY) {
            $this->skip($item, 'This product\'s type in the store is read-only for now.');

            return;
        }

        $fields = $this->mapper->compare($remote, $content);
        $hasChanges = collect($fields)->contains(fn (array $field) => $field['supported'] && $field['changed']);

        $item->update([
            'fields' => $fields,
            'status' => $hasChanges ? WooCommerceSyncItem::READY : WooCommerceSyncItem::SKIPPED,
            'message' => $hasChanges ? null : 'The store already matches this content.',
        ]);
    }

    private function resolveRemoteId(WooCommerceSyncItem $item, LanguageAdapter $adapter, WooCommerceProductLink $link, ContentLanguage $language): bool
    {
        $remoteId = $adapter->remoteIdFor($link, $language);

        if ($remoteId === null) {
            $this->skip($item, "This product has no {$language->label()} version in the store. New translations aren't created.");

            return false;
        }

        $item->update(['remote_id' => $remoteId]);

        return true;
    }

    private function buildRollbackItem(WooCommerceSyncItem $item): void
    {
        $source = WooCommerceSyncItem::query()->findOrFail($item->rollback_of_item_id);
        $remote = $this->woocommerce->getProduct($item->run->store, $item->remote_id);
        $fields = [];

        foreach ($this->writtenFields($source) as $field) {
            $raw = (string) ($remote[$field] ?? '');
            $restored = (string) $source->previous_values[$field];
            $unchanged = $this->mapper->sameValue($field, $raw, (string) $source->applied_values[$field]);

            // A field edited since the sync isn't ours to restore.
            $fields[$field] = [
                'label' => WooCommerceProductMapper::FIELD_LABELS[$field],
                'supported' => $unchanged,
                'blocked' => ! $unchanged,
                'remote_raw' => $raw,
                'remote_hash' => $this->mapper->hash($raw),
                'remote_display' => $this->mapper->display($field, $raw),
                'proposed_wire' => $restored,
                'proposed_display' => $this->mapper->display($field, $restored),
                'changed' => ! $this->mapper->sameValue($field, $raw, $restored),
                'selected' => false,
            ];
        }

        $canRestore = collect($fields)->contains(fn (array $field) => $field['supported'] && $field['changed']);

        $item->update([
            'fields' => $fields,
            'status' => $canRestore ? WooCommerceSyncItem::READY : WooCommerceSyncItem::CONFLICT,
            'message' => $canRestore ? null : 'These fields changed in the store after the sync, so they can\'t be restored safely.',
        ]);
    }

    /**
     * @return list<string> fields that have both a recorded old value and an applied value
     */
    private function writtenFields(WooCommerceSyncItem $item): array
    {
        return array_keys(array_intersect_key($item->previous_values ?? [], $item->applied_values ?? []));
    }

    private function skip(WooCommerceSyncItem $item, string $message): void
    {
        $item->update(['status' => WooCommerceSyncItem::SKIPPED, 'message' => $message]);
    }

    private function finish(WooCommerceSyncRun $run): void
    {
        $counts = $run->items()->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');

        $status = match (true) {
            ($counts[WooCommerceSyncItem::READY] ?? 0) > 0 => WooCommerceSyncRun::READY,
            ($counts[WooCommerceSyncItem::FAILED] ?? 0) > 0 => WooCommerceSyncRun::FAILED,
            ($counts[WooCommerceSyncItem::CONFLICT] ?? 0) > 0 => WooCommerceSyncRun::CONFLICT,
            default => WooCommerceSyncRun::SKIPPED,
        };

        $run->update([
            'status' => $status,
            'completed_at' => $status === WooCommerceSyncRun::READY ? null : now(),
        ]);
    }
}
