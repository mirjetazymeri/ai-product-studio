<?php

namespace App\Http\Controllers\Integrations;

use App\Enums\AiError;
use App\Enums\BulkItemStatus;
use App\Http\Controllers\Controller;
use App\Models\BulkGenerationItem;
use App\Models\Product;
use App\Models\ProductContent;
use App\Models\WooCommerceStore;
use App\Models\WooCommerceSyncItem;
use App\Models\WooCommerceSyncRun;
use App\Services\AI\Exceptions\AiGenerationException;
use App\Services\AI\GenerationQuota;
use App\Services\WooCommerce\Demo\WooCommerceDemoContentService;
use App\Services\WooCommerce\Demo\WooCommerceDemoService;
use App\Services\WooCommerce\Exceptions\WooCommerceException;
use App\Services\WooCommerce\WooCommerceConnectionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;

class WooCommerceDemoController extends Controller
{
    public function __construct(
        private readonly WooCommerceDemoService $demo,
        private readonly WooCommerceDemoContentService $content,
    ) {}

    public function activate(Request $request): RedirectResponse
    {
        return to_route('integrations.demo.show', $this->demo->activate($request->user()))
            ->with('status', 'Demo store ready. Everything here is simulated, and no real store is involved.');
    }

    public function show(WooCommerceStore $store): View
    {
        $this->authorizeDemo($store);

        $productIds = Product::query()->where('demo_store_id', $store->id)->pluck('id');
        $reviews = BulkGenerationItem::query()
            ->where('user_id', $store->user_id)
            ->whereIn('product_id', $productIds)
            ->where('status', BulkItemStatus::Generated->value)
            ->get(['review_status']);

        return view('integrations.demo.show', [
            'store' => $store,
            'records' => $store->demoProducts()->orderBy('remote_id')->get(),
            'links' => $store->links()->get()->keyBy('remote_id'),
            'progress' => [
                'linked' => $productIds->count(),
                'pending' => $reviews->where('review_status', BulkGenerationItem::REVIEW_PENDING)->count(),
                'approved' => $reviews->where('review_status', BulkGenerationItem::REVIEW_APPROVED)->count(),
                'synced' => $store->syncRuns()->whereIn('status', [WooCommerceSyncRun::COMPLETED, WooCommerceSyncRun::PARTIALLY_COMPLETED])->count(),
            ],
        ]);
    }

    public function product(WooCommerceStore $store, int $remoteId, GenerationQuota $quota): View
    {
        $this->authorizeDemo($store);

        $record = $store->demoProducts()->where('remote_id', $remoteId)->firstOrFail();
        $link = $store->links()->with('product.contents')->where('remote_id', $remoteId)->first();

        return view('integrations.demo.product', [
            'store' => $store,
            'record' => $record,
            'link' => $link,
            'descriptive' => $this->demo->descriptiveFields($record),
            'protected' => $this->demo->protectedFields($record),
            'latestReview' => $link === null ? null : BulkGenerationItem::query()->with('generation:id,provider')->where('product_id', $link->product_id)->latest('id')->first(),
            'approvedContent' => $link?->product->contents->first(fn (ProductContent $content) => $content->source->isApprovedForSync()),
            'remainingToday' => $quota->remainingFor($store->user),
        ]);
    }

    public function test(WooCommerceStore $store, WooCommerceConnectionService $connections): RedirectResponse
    {
        $this->authorizeDemo($store);

        $failure = $connections->test($store);

        return $failure === null
            ? back()->with('status', 'Simulated connection test passed. No network request was made.')
            : back()->withErrors(['connection' => 'Simulated response: '.$failure->error->message()]);
    }

    public function outage(Request $request, WooCommerceStore $store): RedirectResponse
    {
        $this->authorizeDemo($store);

        $this->demo->setOutage($store, $request->boolean('outage'));

        return back()->with('status', $request->boolean('outage')
            ? 'Simulated outage on. The demo store now answers every request with HTTP 503.'
            : 'Simulated outage off.');
    }

    public function reset(Request $request, WooCommerceStore $store): RedirectResponse
    {
        $this->authorizeDemo($store);

        $request->validate(['confirm' => ['accepted']], ['confirm.accepted' => 'Confirm that you want to reset the demo store.']);

        $this->demo->reset($store);

        return to_route('integrations.demo.show', $store)->with('status', 'Demo store reset to the original sample catalog. Your real stores, products and generation history weren\'t touched.');
    }

    public function sampleContent(Request $request, WooCommerceStore $store): RedirectResponse
    {
        $this->authorizeDemo($store);

        $validated = $request->validate(['remote_id' => ['nullable', 'integer']]);

        $count = $this->content->generateSamples($store, isset($validated['remote_id']) ? (int) $validated['remote_id'] : null);

        return $count === 0
            ? back()->withErrors(['sample' => 'Nothing to generate. Import products first, or finish reviewing the drafts already waiting.'])
            : to_route('ai.reviews.index')->with('status', "{$count} Sample AI Output ".str('draft')->plural($count).' ready for review. No Gemini request was made and no tokens were used.');
    }

    public function realContent(Request $request, WooCommerceStore $store, int $remoteId): RedirectResponse
    {
        $this->authorizeDemo($store);

        $request->validate(['confirm_real_ai' => ['accepted']], ['confirm_real_ai.accepted' => 'Confirm that a real Gemini request should be sent.']);

        $link = $store->links()->with('product')->where('remote_id', $remoteId)->firstOrFail();

        abort_if($link->isReadOnly(), 422);

        // The same per-minute counter as the throttle:ai-generate route middleware,
        // checked here so this form gets a normal page instead of a JSON error.
        $rateLimitKey = md5('ai-generate'.$request->user()->id);

        if (RateLimiter::tooManyAttempts($rateLimitKey, (int) config('services.gemini.per_minute_limit'))) {
            return back()->withErrors(['gemini' => AiError::RateLimited->message()]);
        }

        RateLimiter::hit($rateLimitKey, 60);

        try {
            $item = $this->content->generateWithGemini($store, $link);
        } catch (AiGenerationException $exception) {
            return back()->withErrors(['gemini' => $exception->error->message()]);
        }

        return to_route('ai.reviews.edit', $item)->with('status', 'Real Gemini draft generated. It used '.number_format((int) $item->generation->total_tokens).' tokens.');
    }

    public function simulateChange(WooCommerceSyncItem $item): RedirectResponse
    {
        Gate::authorize('manage', $item->run);

        abort_unless($item->run->store->isDemo(), 404);

        if ($item->run->status !== WooCommerceSyncRun::READY || $item->status !== WooCommerceSyncItem::READY) {
            return back()->withErrors(['confirm' => 'A remote change can only be simulated while a preview is waiting for confirmation.']);
        }

        try {
            $field = $this->demo->simulateRemoteChange($item);
        } catch (WooCommerceException $exception) {
            return back()->withErrors(['confirm' => 'Simulated response: '.$exception->error->message()]);
        }

        return $field === null
            ? back()->withErrors(['confirm' => 'This product has no changed field to edit.'])
            : back()->with('status', "Simulated: {$field} was edited in the demo store. This preview still shows the older value, so keep that field ticked and confirm to see the conflict.");
    }

    private function authorizeDemo(WooCommerceStore $store): void
    {
        Gate::authorize('manage', $store);

        abort_unless($store->isDemo(), 404);
    }
}
