<?php

namespace App\Http\Controllers\Integrations;

use App\Enums\ContentLanguage;
use App\Http\Controllers\Controller;
use App\Models\WooCommerceProductLink;
use App\Models\WooCommerceStore;
use App\Models\WooCommerceSyncItem;
use App\Models\WooCommerceSyncRun;
use App\Services\WooCommerce\Demo\WooCommerceDemoService;
use App\Services\WooCommerce\Languages\LanguageAdapterFactory;
use App\Services\WooCommerce\WooCommerceSyncPreviewService;
use App\Services\WooCommerce\WooCommerceSyncRunner;
use App\Services\WooCommerce\WooCommerceSyncService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class WooCommerceSyncController extends Controller
{
    public function __construct(
        private readonly WooCommerceSyncPreviewService $preview,
        private readonly WooCommerceSyncService $sync,
        private readonly WooCommerceSyncRunner $runner,
        private readonly LanguageAdapterFactory $languages,
    ) {}

    public function history(Request $request): View
    {
        $runs = $request->user()->wooCommerceSyncRuns()
            ->with('store:id,name,connection_type')
            ->withCount('items')
            ->latest('id')
            ->paginate(20);

        $runs->getCollection()->filter(fn (WooCommerceSyncRun $run) => $run->store->isDemo())->each->load('items');

        return view('integrations.sync.history', ['runs' => $runs]);
    }

    public function create(Request $request, WooCommerceStore $store): View|RedirectResponse
    {
        Gate::authorize('manage', $store);

        $links = $this->selectedLinks($request, $store);

        if ($links->isEmpty()) {
            return to_route('integrations.products', $store)->withErrors(['sync' => 'Select at least one linked product.']);
        }

        $adapter = $this->languages->for($store);

        return view('integrations.sync.create', [
            'store' => $store,
            'links' => $links,
            'languages' => $adapter->languages(),
            'unavailableReason' => $store->isDisconnected() ? 'This store is disconnected.' : $adapter->unavailableReason(),
        ]);
    }

    public function store(Request $request, WooCommerceStore $store): RedirectResponse
    {
        Gate::authorize('manage', $store);

        $adapter = $this->languages->for($store);

        $validated = $request->validate([
            'link_ids' => ['required', 'array', 'min:1', 'max:'.config('woocommerce.sync_max_products')],
            'link_ids.*' => ['integer'],
            'language' => ['required', Rule::enum(ContentLanguage::class)],
        ]);

        $language = ContentLanguage::from($validated['language']);

        if ($store->isDisconnected() || $adapter->unavailableReason() !== null || ! in_array($language, $adapter->languages(), true)) {
            return back()->withErrors(['language' => $adapter->unavailableReason() ?? 'This language can\'t be synchronized with this store.']);
        }

        if ($this->hasActiveRun($store)) {
            return back()->withErrors(['language' => 'Another synchronization is already running for this store.']);
        }

        $links = $this->selectedLinks($request, $store);

        if ($links->isEmpty()) {
            return back()->withErrors(['language' => 'Select at least one linked product.']);
        }

        $run = $this->preview->createRun($request->user(), $store, $links, $language);
        $this->runner->start($run);

        return to_route('integrations.sync.show', $run);
    }

    public function show(WooCommerceSyncRun $run, WooCommerceDemoService $demo): View
    {
        Gate::authorize('manage', $run);

        $run->load('store');
        $items = $run->items()->orderBy('id')->get();

        return view('integrations.sync.show', [
            'run' => $run,
            'items' => $items,
            'protectedFields' => $run->store->isDemo()
                ? $run->store->demoProducts()->whereIn('remote_id', $items->pluck('remote_id')->filter())->get()->mapWithKeys(fn ($record) => [$record->remote_id => $demo->protectedFields($record)])
                : collect(),
        ]);
    }

    public function confirm(Request $request, WooCommerceSyncRun $run): RedirectResponse
    {
        Gate::authorize('manage', $run);

        $validated = $request->validate([
            'selected' => ['required', 'array', 'min:1'],
            'selected.*' => ['array'],
            'selected.*.*' => ['string'],
            'confirm' => ['accepted'],
        ], [
            'selected.required' => 'Select at least one field to update.',
            'confirm.accepted' => 'Confirm that these changes may be written to the store.',
        ]);

        if ($this->hasActiveRun($run->store, $run)) {
            return back()->withErrors(['confirm' => 'Another synchronization is already running for this store.']);
        }

        if ($this->sync->confirm($run, $validated['selected'])) {
            $this->runner->start($run->refresh());
        }

        return to_route('integrations.sync.show', $run);
    }

    public function retry(WooCommerceSyncRun $run): RedirectResponse
    {
        Gate::authorize('manage', $run);

        if ($this->sync->retryFailed($run) > 0) {
            $this->runner->start($run->refresh());
        }

        return to_route('integrations.sync.show', $run);
    }

    public function refresh(Request $request, WooCommerceSyncRun $run): RedirectResponse
    {
        Gate::authorize('manage', $run);

        if ($run->isRollback()) {
            return back()->withErrors(['confirm' => 'Start a new rollback from the original synchronization.']);
        }

        if ($this->hasActiveRun($run->store)) {
            return back()->withErrors(['confirm' => 'Another synchronization is already running for this store.']);
        }

        $links = $run->store->links()->with('product')
            ->whereKey($run->items()->whereIn('status', [WooCommerceSyncItem::CONFLICT, WooCommerceSyncItem::FAILED])->pluck('woocommerce_product_link_id')->filter())
            ->get();

        if ($links->isEmpty()) {
            return back()->withErrors(['confirm' => 'There is nothing to refresh.']);
        }

        $fresh = $this->preview->createRun($request->user(), $run->store, $links, $run->language);
        $this->runner->start($fresh);

        return to_route('integrations.sync.show', $fresh);
    }

    public function rollback(WooCommerceSyncRun $run): RedirectResponse
    {
        Gate::authorize('manage', $run);

        if ($run->store->isDisconnected() || $this->hasActiveRun($run->store)) {
            return back()->withErrors(['confirm' => 'This store can\'t be changed right now.']);
        }

        $rollback = $this->preview->createRollback($run);

        if ($rollback === null) {
            return back()->withErrors(['confirm' => 'This synchronization has no recorded changes to restore.']);
        }

        $this->runner->start($rollback);

        return to_route('integrations.sync.show', $rollback);
    }

    /**
     * Starts from the store's own links, so other users' IDs simply match nothing.
     *
     * @return Collection<int, WooCommerceProductLink>
     */
    private function selectedLinks(Request $request, WooCommerceStore $store): Collection
    {
        $ids = array_filter(array_map('intval', (array) $request->input('link_ids', [])));

        return $store->links()->with('product')->whereKey($ids)->limit((int) config('woocommerce.sync_max_products'))->get();
    }

    private function hasActiveRun(WooCommerceStore $store, ?WooCommerceSyncRun $except = null): bool
    {
        return $store->syncRuns()
            ->whereIn('status', WooCommerceSyncRun::ACTIVE)
            ->when($except !== null, fn ($query) => $query->whereKeyNot($except->id))
            ->exists();
    }
}
