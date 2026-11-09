<?php

namespace App\Http\Controllers\AI;

use App\Enums\AiError;
use App\Enums\BulkItemStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\AI\SaveReviewedContentRequest;
use App\Models\BulkGenerationBatch;
use App\Models\BulkGenerationItem;
use App\Models\ProductContent;
use App\Services\AI\ContentApprovalService;
use App\Services\AI\Exceptions\AiGenerationException;
use App\Services\AI\Quality\ContentReviewer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ContentReviewController extends Controller
{
    private const FILTERS = ['pending' => 'Pending', 'approved' => 'Approved', 'failed' => 'Failed', 'all' => 'All'];

    public function index(Request $request): View
    {
        $user = $request->user();
        $filter = array_key_exists($request->query('status'), self::FILTERS) ? $request->query('status') : 'pending';

        $items = $user->bulkGenerationItems()
            ->with(['product:id,original_name,demo_store_id', 'generation:id,provider'])
            ->when($filter !== 'all', fn (Builder $query) => $this->applyFilter($query, $filter), fn ($query) => $query)
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return view('ai.reviews.index', [
            'items' => $items,
            'filter' => $filter,
            'filters' => self::FILTERS,
            'savedContent' => $this->savedContentKeys($items->getCollection()->pluck('product_id')->all()),
            'batches' => $user->bulkGenerationBatches()
                ->withCount([
                    'items',
                    'items as generated_count' => fn ($query) => $query->where('status', BulkItemStatus::Generated->value),
                    'items as failed_count' => fn ($query) => $query->where('status', BulkItemStatus::Failed->value),
                ])
                ->latest('id')
                ->limit(5)
                ->get(),
            'isRunning' => $user->bulkGenerationBatches()->where('status', BulkGenerationBatch::RUNNING)->exists(),
        ]);
    }

    public function edit(BulkGenerationItem $item, ContentReviewer $reviewer): View|RedirectResponse
    {
        Gate::authorize('review', $item);

        if ($item->status !== BulkItemStatus::Generated || $item->review_status !== BulkGenerationItem::REVIEW_PENDING) {
            return to_route('ai.reviews.index', ['status' => 'all']);
        }

        $content = $item->reviewableContent();

        return view('ai.reviews.edit', [
            'item' => $item->load('product', 'generation'),
            'content' => $content,
            'existing' => $item->product->contents()->where('language', $item->language->value)->first(),
            'review' => $reviewer->review($item->product, $content),
        ]);
    }

    public function update(SaveReviewedContentRequest $request, BulkGenerationItem $item, ContentApprovalService $approval): RedirectResponse
    {
        if ($item->status !== BulkItemStatus::Generated || $item->review_status !== BulkGenerationItem::REVIEW_PENDING) {
            return to_route('ai.reviews.index', ['status' => 'all']);
        }

        $fields = $request->contentFields();

        if (! $request->approves()) {
            $item->update(['edited_content' => $fields]);

            return back()->with('status', 'Your edits were saved. This item is still waiting for approval.');
        }

        try {
            $approval->approve($item, $fields, $request->confirmsOverwrite());
        } catch (AiGenerationException $exception) {
            $key = $exception->error === AiError::ContentExists ? 'confirm_overwrite' : 'title';

            return back()->withInput()->withErrors([$key => $exception->error->message()]);
        }

        return to_route('ai.reviews.index')->with('status', 'Content approved and saved.');
    }

    /**
     * Demo drafts only: real drafts keep their existing review options.
     */
    public function reject(BulkGenerationItem $item): RedirectResponse
    {
        Gate::authorize('review', $item);

        abort_unless($item->product->isDemo(), 404);

        if ($item->status === BulkItemStatus::Generated && $item->review_status === BulkGenerationItem::REVIEW_PENDING) {
            $item->update(['review_status' => BulkGenerationItem::REVIEW_REJECTED, 'reviewed_at' => now()]);
        }

        return to_route('ai.reviews.index')->with('status', 'Draft rejected. Nothing was saved to the product.');
    }

    public function bulkApprove(Request $request, ContentApprovalService $approval): RedirectResponse
    {
        $validated = $request->validate([
            'item_ids' => ['required', 'array', 'min:1', 'max:100'],
            'item_ids.*' => ['integer'],
            'confirm_overwrite' => ['nullable', 'boolean'],
        ], ['item_ids.required' => 'Select at least one item to approve.']);

        // Limited to the user's own items, so other users' IDs simply match nothing.
        $items = $request->user()->bulkGenerationItems()
            ->with(['generation', 'product'])
            ->whereIn('id', $validated['item_ids'])
            ->where('status', BulkItemStatus::Generated->value)
            ->where('review_status', BulkGenerationItem::REVIEW_PENDING)
            ->get();

        $result = $approval->approveMany($items, (bool) ($validated['confirm_overwrite'] ?? false));

        $message = "{$result['approved']} approved.";

        if ($result['conflicts'] > 0) {
            $message .= " {$result['conflicts']} left pending because they already have saved content. Tick the replace option to overwrite it.";
        }

        if ($result['failed'] > 0) {
            $message .= " {$result['failed']} couldn't be approved.";
        }

        return back()->with('status', $message);
    }

    /**
     * @param  Builder<BulkGenerationItem>  $query
     */
    private function applyFilter($query, string $filter)
    {
        return match ($filter) {
            'pending' => $query->where('status', BulkItemStatus::Generated->value)->where('review_status', BulkGenerationItem::REVIEW_PENDING),
            'approved' => $query->where('review_status', BulkGenerationItem::REVIEW_APPROVED),
            'failed' => $query->where('status', BulkItemStatus::Failed->value),
        };
    }

    /**
     * @param  list<int>  $productIds
     * @return array<string, true>
     */
    private function savedContentKeys(array $productIds): array
    {
        return ProductContent::query()
            ->whereIn('product_id', $productIds)
            ->get(['product_id', 'language'])
            ->mapWithKeys(fn (ProductContent $content) => ["{$content->product_id}:{$content->language->value}" => true])
            ->all();
    }
}
