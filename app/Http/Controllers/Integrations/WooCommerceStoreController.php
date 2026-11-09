<?php

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Http\Requests\Integrations\SaveWooCommerceStoreRequest;
use App\Models\WooCommerceStore;
use App\Services\WooCommerce\Exceptions\WooCommerceException;
use App\Services\WooCommerce\Http\StoreUrlGuard;
use App\Services\WooCommerce\WooCommerceConnectionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class WooCommerceStoreController extends Controller
{
    public function __construct(
        private readonly WooCommerceConnectionService $connections,
        private readonly StoreUrlGuard $guard,
    ) {}

    public function index(Request $request): View
    {
        return view('integrations.index', [
            'stores' => $request->user()->wooCommerceStores()->withCount('links')->latest('id')->get(),
        ]);
    }

    public function create(): View
    {
        return view('integrations.form', ['store' => new WooCommerceStore]);
    }

    public function store(SaveWooCommerceStoreRequest $request): RedirectResponse
    {
        $url = $this->checkedUrl($request->validated('url'));

        if ($this->connections->isDuplicate($request->user(), $url)) {
            return back()->withInput($request->except(['consumer_key', 'consumer_secret']))->withErrors(['url' => 'You\'ve already connected this store.']);
        }

        $store = $request->user()->wooCommerceStores()->create([
            'name' => $request->validated('name'),
            'url' => $url,
            'consumer_key' => $request->validated('consumer_key'),
            'consumer_secret' => $request->validated('consumer_secret'),
            'status' => WooCommerceStore::UNTESTED,
        ]);

        return $this->redirectWithTestResult($store);
    }

    public function edit(WooCommerceStore $store): View
    {
        $this->authorizeRealStore($store);

        return view('integrations.form', ['store' => $store]);
    }

    public function update(SaveWooCommerceStoreRequest $request, WooCommerceStore $store): RedirectResponse
    {
        $this->authorizeRealStore($store);

        $url = $this->checkedUrl($request->validated('url'));

        if ($this->connections->isDuplicate($request->user(), $url, $store->id)) {
            return back()->withInput($request->except(['consumer_key', 'consumer_secret']))->withErrors(['url' => 'You\'ve already connected this store.']);
        }

        // Linked remote IDs only mean something in the store they came from.
        if ($url !== $store->url && $store->links()->exists()) {
            return back()->withInput($request->except(['consumer_key', 'consumer_secret']))->withErrors(['url' => 'This store has linked products, so its address can\'t be changed.']);
        }

        $attributes = ['name' => $request->validated('name'), 'url' => $url];

        if (filled($request->validated('consumer_key'))) {
            $attributes += [
                'consumer_key' => $request->validated('consumer_key'),
                'consumer_secret' => $request->validated('consumer_secret'),
                'status' => WooCommerceStore::UNTESTED,
                'disconnected_at' => null,
            ];
        }

        $store->update($attributes);

        return $store->isDisconnected()
            ? to_route('integrations.index')->with('status', 'Store updated. Add its credentials to reconnect it.')
            : $this->redirectWithTestResult($store);
    }

    public function test(WooCommerceStore $store): RedirectResponse
    {
        Gate::authorize('manage', $store);

        return $this->redirectWithTestResult($store);
    }

    public function disconnect(Request $request, WooCommerceStore $store): RedirectResponse
    {
        $this->authorizeRealStore($store);

        $request->validate(['confirm' => ['accepted']], ['confirm.accepted' => 'Confirm that you want to disconnect this store.']);

        $this->connections->disconnect($store);

        return to_route('integrations.index')->with('status', 'Store disconnected. Its saved credentials were removed, and nothing was changed in the store.');
    }

    /**
     * A demo store has no address or credentials to edit.
     */
    private function authorizeRealStore(WooCommerceStore $store): void
    {
        Gate::authorize('manage', $store);

        abort_if($store->isDemo(), 404);
    }

    private function checkedUrl(string $url): string
    {
        try {
            $normalised = $this->guard->normalise($url);
            $this->guard->safeAddressFor((string) parse_url($normalised, PHP_URL_HOST));

            return $normalised;
        } catch (WooCommerceException $exception) {
            throw ValidationException::withMessages(['url' => $exception->error->message()]);
        }
    }

    private function redirectWithTestResult(WooCommerceStore $store): RedirectResponse
    {
        $failure = $this->connections->test($store);

        return $failure === null
            ? to_route('integrations.index')->with('status', "Connected to {$store->name}. The test only read from the store.")
            : to_route('integrations.index')->withErrors(['connection' => $failure->error->message()]);
    }
}
