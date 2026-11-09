<?php

namespace App\Http\Controllers\AI;

use App\Enums\AiError;
use App\Enums\ContentLanguage;
use App\Http\Controllers\Controller;
use App\Http\Requests\AI\StartBulkGenerationRequest;
use App\Models\BulkGenerationBatch;
use App\Services\AI\BulkGenerationService;
use App\Services\AI\Exceptions\AiGenerationException;
use App\Services\AI\GenerationPreferences;
use App\Services\AI\GenerationQuota;
use App\Services\AI\ProductContentGenerator;
use App\Services\Catalog\ProductSelection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;

class BulkGenerationController extends Controller
{
    public function create(Request $request, ProductSelection $selection, ProductContentGenerator $generator, GenerationQuota $quota): View|RedirectResponse
    {
        $input = $request->only(['product_ids', 'all_matching', 'search', 'status']);

        if (! $selection->hasExplicitSelection($input)) {
            return to_route('products.index');
        }

        $limit = (int) config('catalog.bulk_generation_max_products');
        $products = $selection->query($request->user(), $input)->orderBy('original_name')->limit($limit + 1)->get();

        return view('ai.bulk.create', [
            'products' => $products->take($limit),
            'wasTruncated' => $products->count() > $limit,
            'limit' => $limit,
            'canGenerate' => fn ($product) => $generator->hasEnoughData($product),
            'remaining' => $quota->remainingFor($request->user()),
            'perMinute' => (int) config('services.gemini.per_minute_limit'),
            'languages' => ContentLanguage::cases(),
            'preferences' => GenerationPreferences::fromHistory(
                $request->user()->aiGenerations()->whereNotNull('preferences')->latest('id')->value('preferences')
            ),
            'token' => (string) Str::uuid(),
        ]);
    }

    public function store(StartBulkGenerationRequest $request, BulkGenerationService $bulk, GenerationQuota $quota): RedirectResponse
    {
        $user = $request->user();
        $productIds = array_map('intval', $request->validated('product_ids'));
        $languages = $request->validated('languages');

        $eligible = $bulk->eligibleProducts($user, $productIds)->count();

        if ($eligible === 0) {
            return back()->withInput()->withErrors(['product_ids' => 'None of these products have enough information. Add a category, material or details first.']);
        }

        try {
            $bulk->start($user, $productIds, $languages, $request->preferences(), $request->validated('submission_token'));
        } catch (AiGenerationException $exception) {
            if ($exception->error !== AiError::DailyLimitReached) {
                return back()->withInput()->withErrors(['product_ids' => $exception->error->message()]);
            }

            $needed = $bulk->estimateRequests($eligible, count($languages));
            $remaining = $quota->remainingFor($user);

            return back()->withInput()->withErrors([
                'product_ids' => "This batch needs {$needed} AI requests, but only {$remaining} are left today. Choose fewer products or languages.",
            ]);
        }

        return to_route('ai.reviews.index', ['status' => 'all'])->with('status', 'Bulk generation has started. Results will appear here for review.');
    }

    public function retry(BulkGenerationBatch $batch, BulkGenerationService $bulk): RedirectResponse
    {
        Gate::authorize('retry', $batch);

        $result = $bulk->retryFailed($batch);

        if ($result['retried'] === 0) {
            return back()->withErrors(['batch' => $result['remaining'] > 0
                ? 'There are no AI requests left today. Please try again tomorrow.'
                : 'There is nothing to retry right now.']);
        }

        $message = "Retrying {$result['retried']} failed items.";

        if ($result['remaining'] > 0) {
            $message .= " {$result['remaining']} stay failed because today's allowance ran out.";
        }

        return back()->with('status', $message);
    }
}
