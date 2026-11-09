<?php

namespace App\Services\WooCommerce;

use App\Enums\WooCommerceError;
use App\Models\WooCommerceSyncItem;
use App\Models\WooCommerceSyncRun;
use App\Services\WooCommerce\Exceptions\WooCommerceException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Writes confirmed changes, one product at a time.
 *
 * WooCommerce has no conditional update, so the store is read again immediately before
 * each write. A small window remains between that read and the write, in which someone
 * else's edit could be overwritten. See docs/woocommerce-integration.md.
 */
class WooCommerceSyncService
{
    private const STALE_RUNNING_MINUTES = 15;

    private const MAX_ATTEMPTS = 5;

    public function __construct(
        private readonly WooCommerceService $woocommerce,
        private readonly WooCommerceProductMapper $mapper,
    ) {}

    /**
     * @param  array<int, list<string>>  $selected  field names by item ID
     * @return bool false if this preview was already confirmed
     */
    public function confirm(WooCommerceSyncRun $run, array $selected): bool
    {
        return DB::transaction(function () use ($run, $selected) {
            $claimed = WooCommerceSyncRun::query()
                ->whereKey($run->id)
                ->where('status', WooCommerceSyncRun::READY)
                ->update(['status' => WooCommerceSyncRun::QUEUED, 'confirmed_at' => now()]);

            if ($claimed !== 1) {
                return false;
            }

            foreach ($run->items()->where('status', WooCommerceSyncItem::READY)->get() as $item) {
                $this->applySelection($item, $selected[$item->id] ?? []);
            }

            return true;
        });
    }

    /**
     * Only changed, supported, allowlisted fields can ever be selected.
     *
     * @param  list<string>  $requested
     */
    private function applySelection(WooCommerceSyncItem $item, array $requested): void
    {
        $fields = $item->fields;
        $any = false;

        foreach ($fields as $name => $field) {
            $fields[$name]['selected'] = in_array($name, $requested, true)
                && in_array($name, WooCommerceService::WRITABLE_FIELDS, true)
                && $field['supported'] && $field['changed'];

            $any = $any || $fields[$name]['selected'];
        }

        $item->update([
            'fields' => $fields,
            'status' => $any ? WooCommerceSyncItem::QUEUED : WooCommerceSyncItem::SKIPPED,
            'message' => $any ? null : 'No fields were selected.',
        ]);
    }

    /**
     * @return int|null seconds until the next item may run, or null when the run is finished
     */
    public function processNext(WooCommerceSyncRun $run): ?int
    {
        $this->requeueStaleItems($run);

        WooCommerceSyncRun::query()->whereKey($run->id)->where('status', WooCommerceSyncRun::QUEUED)->update(['status' => WooCommerceSyncRun::RUNNING]);

        if ($run->store->isDisconnected()) {
            $this->failRemaining($run, WooCommerceError::Disconnected);
            $this->finish($run);

            return null;
        }

        $item = $this->claimNextItem($run);

        if ($item === null) {
            if (! $run->items()->where('status', WooCommerceSyncItem::RUNNING)->exists()) {
                $this->finish($run);
            }

            return null;
        }

        return $this->processItem($run, $item);
    }

    /**
     * Failed items only. Each one reads the store first, so nothing is applied twice.
     */
    public function retryFailed(WooCommerceSyncRun $run): int
    {
        if (! $run->isFinished() || $run->store->isDisconnected()) {
            return 0;
        }

        return DB::transaction(function () use ($run) {
            $claimed = WooCommerceSyncRun::query()
                ->whereKey($run->id)
                ->where('status', $run->status)
                ->update(['status' => WooCommerceSyncRun::QUEUED, 'completed_at' => null, 'error_summary' => null]);

            if ($claimed !== 1) {
                return 0;
            }

            return $run->items()
                ->where('status', WooCommerceSyncItem::FAILED)
                ->where('attempts', '<', self::MAX_ATTEMPTS)
                ->update(['status' => WooCommerceSyncItem::QUEUED, 'error_code' => null, 'message' => null]);
        });
    }

    private function requeueStaleItems(WooCommerceSyncRun $run): void
    {
        $run->items()
            ->where('status', WooCommerceSyncItem::RUNNING)
            ->where('updated_at', '<', now()->subMinutes(self::STALE_RUNNING_MINUTES))
            ->update(['status' => WooCommerceSyncItem::QUEUED]);
    }

    /**
     * Claimed with a conditional update, so two workers can't send the same write.
     */
    private function claimNextItem(WooCommerceSyncRun $run): ?WooCommerceSyncItem
    {
        $item = $run->items()->where('status', WooCommerceSyncItem::QUEUED)->orderBy('id')->first();

        if ($item === null) {
            return null;
        }

        $claimed = WooCommerceSyncItem::query()
            ->whereKey($item->id)
            ->where('status', WooCommerceSyncItem::QUEUED)
            ->update(['status' => WooCommerceSyncItem::RUNNING, 'attempts' => $item->attempts + 1, 'updated_at' => now()]);

        return $claimed === 1 ? $item->refresh() : null;
    }

    private function processItem(WooCommerceSyncRun $run, WooCommerceSyncItem $item): ?int
    {
        try {
            $current = $this->woocommerce->getProduct($run->store, $item->remote_id);
        } catch (WooCommerceException $exception) {
            return $this->handleFailure($run, $item, $exception);
        }

        if (($reason = $this->blockingReason($run, $item, $current)) !== null) {
            $this->markConflict($item, $reason);

            return 0;
        }

        [$toWrite, $conflicts, $alreadyApplied] = $this->classifyFields($item, $current);

        if ($conflicts !== []) {
            $item->update([
                'status' => WooCommerceSyncItem::CONFLICT,
                'conflicts' => $conflicts,
                'message' => 'The store changed after this preview, so nothing was overwritten. Refresh the preview to review the new values.',
            ]);

            return 0;
        }

        if ($toWrite === []) {
            $this->complete($run, $item, $alreadyApplied, [], $current);

            return 0;
        }

        // Recorded before the write, so an interrupted update can still be inspected.
        $item->update(['previous_values' => array_intersect_key($this->rawValues($current), $toWrite)]);

        try {
            $response = $this->woocommerce->updateProduct($run->store, $item->remote_id, $toWrite);
        } catch (WooCommerceException $exception) {
            return $this->handleFailure($run, $item, $exception);
        }

        $this->complete($run, $item, $alreadyApplied, $toWrite, $response);

        return 0;
    }

    /**
     * @param  array<string, mixed>  $current
     */
    private function blockingReason(WooCommerceSyncRun $run, WooCommerceSyncItem $item, array $current): ?string
    {
        if ($this->mapper->syncModeFor((string) ($current['type'] ?? '')) === 'read_only') {
            return 'This product\'s type in the store is now read-only, so nothing was changed.';
        }

        return $run->isRollback() ? null : $this->localContentChange($run, $item);
    }

    /**
     * Content that was edited or un-approved after the preview must not be sent unseen.
     */
    private function localContentChange(WooCommerceSyncRun $run, WooCommerceSyncItem $item): ?string
    {
        $content = $item->product?->contents()->where('language', $run->language->value)->first();
        $message = 'The approved content changed after this preview, so nothing was sent. Refresh the preview to review it.';

        if ($content === null || ! $content->source->isApprovedForSync()) {
            return $message;
        }

        $proposed = $this->mapper->proposedValues($content);

        foreach ($item->fields as $name => $field) {
            if ($field['selected'] && ($proposed[$name] ?? null) !== $field['proposed_wire']) {
                return $message;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $current
     * @return array{array<string, string>, array<string, string>, array<string, string>}
     */
    private function classifyFields(WooCommerceSyncItem $item, array $current): array
    {
        $toWrite = [];
        $conflicts = [];
        $alreadyApplied = [];

        foreach ($item->fields as $name => $field) {
            if (! $field['selected'] || ! in_array($name, WooCommerceService::WRITABLE_FIELDS, true)) {
                continue;
            }

            $raw = (string) ($current[$name] ?? '');

            if ($this->mapper->sameValue($name, $raw, $field['proposed_wire'])) {
                $alreadyApplied[$name] = $raw;
            } elseif (hash_equals($field['remote_hash'], $this->mapper->hash($raw))) {
                $toWrite[$name] = $field['proposed_wire'];
            } else {
                $conflicts[$name] = $this->mapper->display($name, $raw);
            }
        }

        return [$toWrite, $conflicts, $alreadyApplied];
    }

    /**
     * @param  array<string, mixed>  $product
     * @return array<string, string>
     */
    private function rawValues(array $product): array
    {
        return array_map('strval', array_intersect_key($product, array_flip(WooCommerceService::WRITABLE_FIELDS)));
    }

    /**
     * Stores what the store actually reports, which can differ from what was sent.
     *
     * @param  array<string, string>  $alreadyApplied
     * @param  array<string, string>  $written
     * @param  array<string, mixed>  $response
     */
    private function complete(WooCommerceSyncRun $run, WooCommerceSyncItem $item, array $alreadyApplied, array $written, array $response): void
    {
        $applied = $alreadyApplied;
        $mismatched = [];

        foreach ($written as $name => $wire) {
            $reported = (string) ($response[$name] ?? '');

            if ($this->mapper->sameValue($name, $reported, $wire)) {
                $applied[$name] = $reported;
            } else {
                $mismatched[] = WooCommerceProductMapper::FIELD_LABELS[$name];
                $applied[$name] = $reported;
            }
        }

        $item->update([
            'status' => $mismatched === [] ? WooCommerceSyncItem::COMPLETED : WooCommerceSyncItem::PARTIALLY_COMPLETED,
            'applied_values' => $applied,
            'error_code' => null,
            'message' => $mismatched === [] ? null : 'The store saved a different value for: '.implode(', ', $mismatched).'.',
        ]);

        $item->link?->update([
            'last_synced_at' => now(),
            'remote_modified_gmt' => is_string($response['date_modified_gmt'] ?? null) ? mb_substr($response['date_modified_gmt'], 0, 40) : $item->link->remote_modified_gmt,
        ]);
    }

    private function handleFailure(WooCommerceSyncRun $run, WooCommerceSyncItem $item, WooCommerceException $exception): ?int
    {
        $error = $exception->error;

        if ($error === WooCommerceError::RateLimited && $item->attempts < self::MAX_ATTEMPTS) {
            $item->update(['status' => WooCommerceSyncItem::QUEUED]);

            return $exception->retryAfter ?? 30;
        }

        if ($error === WooCommerceError::Disconnected) {
            $item->update(['status' => WooCommerceSyncItem::FAILED, 'error_code' => $error->value, 'message' => $error->message()]);
            $this->failRemaining($run, $error);
            $this->finish($run);

            return null;
        }

        Log::warning('WooCommerce sync item failed.', ['run_id' => $run->id, 'item_id' => $item->id, 'error' => $error->value]);

        $item->update(['status' => WooCommerceSyncItem::FAILED, 'error_code' => $error->value, 'message' => $error->message()]);

        return 0;
    }

    private function markConflict(WooCommerceSyncItem $item, string $message): void
    {
        $item->update(['status' => WooCommerceSyncItem::CONFLICT, 'message' => $message]);
    }

    private function failRemaining(WooCommerceSyncRun $run, WooCommerceError $error): void
    {
        $run->items()->where('status', WooCommerceSyncItem::QUEUED)->update([
            'status' => WooCommerceSyncItem::FAILED,
            'error_code' => $error->value,
            'message' => $error->message(),
        ]);
    }

    private function finish(WooCommerceSyncRun $run): void
    {
        $counts = $run->items()->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');
        $count = fn (string $status) => (int) ($counts[$status] ?? 0);

        $succeeded = $count(WooCommerceSyncItem::COMPLETED) + $count(WooCommerceSyncItem::PARTIALLY_COMPLETED);
        $failed = $count(WooCommerceSyncItem::FAILED);
        $conflicts = $count(WooCommerceSyncItem::CONFLICT);

        $status = match (true) {
            $succeeded > 0 && ($failed > 0 || $conflicts > 0 || $count(WooCommerceSyncItem::PARTIALLY_COMPLETED) > 0) => WooCommerceSyncRun::PARTIALLY_COMPLETED,
            $succeeded > 0 => WooCommerceSyncRun::COMPLETED,
            $conflicts > 0 && $failed === 0 => WooCommerceSyncRun::CONFLICT,
            $failed > 0 => WooCommerceSyncRun::FAILED,
            default => WooCommerceSyncRun::SKIPPED,
        };

        $run->update([
            'status' => $status,
            'error_summary' => $failed + $conflicts > 0 ? "{$failed} failed, {$conflicts} with conflicts." : null,
            'completed_at' => now(),
        ]);

        if ($succeeded > 0) {
            $run->store->update(['last_sync_at' => now()]);
        }
    }
}
