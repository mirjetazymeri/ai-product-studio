<?php

namespace App\Http\Controllers\Integrations;

use App\Enums\CatalogTransferStatus;
use App\Enums\ContentLanguage;
use App\Http\Controllers\Controller;
use App\Http\Requests\Integrations\ConfirmWooCommerceImportRequest;
use App\Jobs\ApplyWooCommerceImport;
use App\Jobs\FetchWooCommerceProducts;
use App\Models\CatalogImportRow;
use App\Models\CatalogTransfer;
use App\Models\Product;
use App\Models\WooCommerceStore;
use App\Services\WooCommerce\WooCommerceProductImporter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

class WooCommerceImportController extends Controller
{
    private const MAX_REVIEW_MATCHES = 200;

    public function __construct(private readonly WooCommerceProductImporter $importer) {}

    public function create(WooCommerceStore $store): View|RedirectResponse
    {
        Gate::authorize('manage', $store);

        if ($store->isDisconnected()) {
            return to_route('integrations.index')->withErrors(['connection' => 'Reconnect this store before importing.']);
        }

        return view('integrations.imports.create', [
            'store' => $store,
            'languages' => $store->isDemo() ? [ContentLanguage::English] : ContentLanguage::cases(),
            'detectedLanguage' => $store->capabilities['language']['default'] ?? null,
        ]);
    }

    public function store(Request $request, WooCommerceStore $store): RedirectResponse
    {
        Gate::authorize('manage', $store);

        if ($store->isDisconnected()) {
            return to_route('integrations.index')->withErrors(['connection' => 'Reconnect this store before importing.']);
        }

        $validated = $request->validate(['language' => ['required', Rule::enum(ContentLanguage::class)]]);

        // The sample catalog is written in English.
        $language = $store->isDemo() ? ContentLanguage::English : ContentLanguage::from($validated['language']);

        $transfer = Cache::lock("woocommerce:import:store:{$store->id}", 10)->block(5, function () use ($store, $language) {
            return $this->importer->activeImportFor($store) ?? $this->importer->start($store, $language);
        });

        if ($transfer->status === CatalogTransferStatus::Processing && (int) $transfer->options['next_page'] === 1) {
            $this->continueFetching($transfer);
        }

        return to_route('integrations.imports.show', $transfer);
    }

    public function show(CatalogTransfer $transfer): View
    {
        $this->authorizeImport($transfer);

        $store = $this->importer->storeFor($transfer);

        $matches = $transfer->rows()
            ->where('status', CatalogImportRow::VALID)
            ->whereIn('match_type', [WooCommerceProductImporter::SKU_MATCH, WooCommerceProductImporter::AMBIGUOUS])
            ->orderBy('id')
            ->limit(self::MAX_REVIEW_MATCHES)
            ->get();

        return view('integrations.imports.show', [
            'transfer' => $transfer,
            'store' => $store,
            'summary' => $transfer->summary ?? [],
            'demoResults' => $store->isDemo() && $transfer->status->isFinished()
                ? $transfer->rows()->where('status', CatalogImportRow::VALID)->orderBy('id')->get()
                : collect(),
            'matches' => $matches,
            'localProducts' => Product::query()->where('user_id', $transfer->user_id)->whereKey($matches->pluck('payload.local_product_id')->filter())->get()->keyBy('id'),
            'maxMatches' => self::MAX_REVIEW_MATCHES,
            'linkedRows' => $transfer->rows()->where('match_type', WooCommerceProductImporter::LINKED)->orderBy('id')->limit(50)->get(),
            'newRows' => $transfer->rows()->where('status', CatalogImportRow::VALID)->whereNull('match_type')->orderBy('id')->limit($store->isDemo() ? 50 : 10)->get(),
            'problemRows' => $transfer->rows()
                ->where(fn ($query) => $query->where('status', CatalogImportRow::INVALID)->orWhere('result', CatalogImportRow::FAILED))
                ->orderBy('id')->limit(50)->get(),
            'refreshableFields' => WooCommerceProductImporter::REFRESHABLE_FIELDS,
        ]);
    }

    public function confirm(ConfirmWooCommerceImportRequest $request, CatalogTransfer $transfer): RedirectResponse
    {
        $this->authorizeImport($transfer);

        if ($this->importer->confirm($transfer, array_map('strval', $request->validated('decisions') ?? []), $request->importOptions())) {
            $this->runApply($transfer);
        }

        return to_route('integrations.imports.show', $transfer);
    }

    public function retry(CatalogTransfer $transfer): RedirectResponse
    {
        $this->authorizeImport($transfer);

        $isFetching = ($transfer->options['phase'] ?? null) === 'fetching';

        $claimed = CatalogTransfer::query()
            ->whereKey($transfer->id)
            ->where('status', CatalogTransferStatus::Failed->value)
            ->update([
                'status' => ($isFetching ? CatalogTransferStatus::Processing : CatalogTransferStatus::Queued)->value,
                'error_summary' => null,
                'completed_at' => null,
            ]);

        if ($claimed === 1) {
            $isFetching ? $this->continueFetching($transfer->refresh()) : $this->runApply($transfer->refresh());
        }

        return to_route('integrations.imports.show', $transfer);
    }

    /**
     * Small stores finish during the request. Anything larger continues on the queue.
     */
    private function continueFetching(CatalogTransfer $transfer): void
    {
        $inlinePages = $this->importer->storeFor($transfer)->isDemo() ? PHP_INT_MAX : config('woocommerce.import_inline_pages');

        for ($page = 0; $page < $inlinePages; $page++) {
            $delay = $this->importer->fetchNextPage($transfer->refresh());

            if ($delay === null) {
                return;
            }

            if ($delay > 0) {
                FetchWooCommerceProducts::dispatch($transfer->id)->delay($delay);

                return;
            }
        }

        FetchWooCommerceProducts::dispatch($transfer->id);
    }

    private function runApply(CatalogTransfer $transfer): void
    {
        $remaining = $transfer->rows()->where('status', CatalogImportRow::VALID)->whereNull('result')->count();

        if ($remaining > config('woocommerce.import_inline_rows')) {
            ApplyWooCommerceImport::dispatch($transfer->id);

            return;
        }

        try {
            ApplyWooCommerceImport::dispatchSync($transfer->id);
        } catch (Throwable $exception) {
            report($exception);

            $this->importer->fail($transfer->refresh(), 'The import stopped before finishing. Retrying continues from where it stopped.');
        }
    }

    private function authorizeImport(CatalogTransfer $transfer): void
    {
        Gate::authorize('view', $transfer);

        abort_unless($transfer->file_type === WooCommerceProductImporter::FILE_TYPE, 404);
    }
}
