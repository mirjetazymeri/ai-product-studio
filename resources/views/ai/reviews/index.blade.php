<x-layouts.app title="Content Review">
    <x-page-header title="Content Review" description="AI drafts from bulk generation. Nothing reaches a product until you approve it." />

    @if ($items->contains(fn ($item) => $item->product->isDemo()))
        <x-demo-banner>Items marked Sample AI Output are fixed example text for demo products, not written by Gemini.</x-demo-banner>
    @endif

    @if ($errors->any())
        <div role="alert" class="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-red-200">{{ $errors->first() }}</div>
    @endif

    @if ($batches->isNotEmpty())
        <section class="card" @if ($isRunning) data-auto-refresh="5000" @endif>
            <h2 class="border-b border-gray-200 px-5 py-4 text-sm font-semibold">Recent batches</h2>
            <ul class="divide-y divide-gray-100">
                @foreach ($batches as $batch)
                    @php($done = $batch->generated_count + $batch->failed_count)
                    <li class="flex flex-col gap-3 px-5 py-3 sm:flex-row sm:items-center sm:justify-between">
                        <div class="min-w-0 flex-1">
                            <p class="text-sm">
                                {{ $batch->created_at->format('M j, H:i') }}
                                <span class="text-gray-500">· {{ $batch->generated_count }} generated, {{ $batch->failed_count }} failed, of {{ $batch->items_count }}</span>
                            </p>
                            <div class="mt-1.5 h-1.5 max-w-sm overflow-hidden rounded-full bg-gray-200" aria-hidden="true">
                                <div class="h-full rounded-full bg-indigo-600" style="width: {{ $batch->items_count > 0 ? (int) round($done / $batch->items_count * 100) : 0 }}%"></div>
                            </div>
                            @if ($batch->status === App\Models\BulkGenerationBatch::RUNNING)
                                <p class="mt-1.5 text-xs text-gray-500">Running in the background. If nothing changes, make sure the queue worker is running: <code class="font-mono">php artisan queue:work</code></p>
                            @endif
                        </div>
                        @if ($batch->status !== App\Models\BulkGenerationBatch::RUNNING && $batch->failed_count > 0)
                            <form method="POST" action="{{ route('ai.bulk.retry', $batch) }}" data-single-submit>
                                @csrf
                                <button type="submit" class="btn btn-secondary">Retry {{ $batch->failed_count }} failed</button>
                            </form>
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    <nav class="flex gap-1 overflow-x-auto" aria-label="Filter by status">
        @foreach ($filters as $key => $label)
            <a href="{{ route('ai.reviews.index', ['status' => $key]) }}" @if ($filter === $key) aria-current="page" @endif @class([
                'rounded-lg px-3 py-1.5 text-sm font-medium whitespace-nowrap transition-colors',
                'bg-indigo-50 text-indigo-700' => $filter === $key,
                'text-gray-600 hover:bg-gray-100' => $filter !== $key,
            ])>{{ $label }}</a>
        @endforeach
    </nav>

    <div class="card">
        @if ($items->isEmpty())
            <x-empty-state icon="check" title="Nothing here" description="Select products on the Products page and choose Generate Content with AI.">
                <a href="{{ route('products.index') }}" class="btn btn-secondary">Go to products</a>
            </x-empty-state>
        @else
            <form method="POST" action="{{ route('ai.reviews.approve') }}" data-single-submit>
                @csrf

                @if ($filter === 'pending')
                    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 px-5 py-3">
                        <label class="flex items-center gap-2 text-sm text-amber-800">
                            <input type="checkbox" name="confirm_overwrite" value="1" class="size-4 rounded accent-indigo-600">
                            Replace saved content where it exists
                        </label>
                        <button type="submit" class="btn btn-primary">Approve selected</button>
                    </div>
                @endif

                <ul class="divide-y divide-gray-100">
                    @foreach ($items as $item)
                        @php($isPending = $item->status === App\Enums\BulkItemStatus::Generated && $item->review_status === App\Models\BulkGenerationItem::REVIEW_PENDING)
                        <li class="flex items-center gap-3 px-5 py-3">
                            @if ($filter === 'pending')
                                <input type="checkbox" name="item_ids[]" value="{{ $item->id }}" aria-label="Select {{ $item->product->original_name }} {{ $item->language->label() }}" class="size-4 shrink-0 rounded accent-indigo-600">
                            @endif

                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-medium">{{ $item->product->original_name }}</p>
                                <p class="mt-0.5 text-xs text-gray-500">
                                    {{ $item->language->label() }}
                                    @if ($item->product->isDemo())
                                        · <span class="font-medium text-amber-800">{{ $item->generation?->isSample() ? 'Sample AI Output' : 'Real Gemini, demo product' }}</span>
                                    @endif
                                    @isset($savedContent["{$item->product_id}:{$item->language->value}"])
                                        @if ($isPending)
                                            · <span class="text-amber-700">Has saved content</span>
                                        @endif
                                    @endisset
                                </p>
                                @if ($item->errorMessage())
                                    <p class="mt-1 text-sm text-red-700">{{ $item->errorMessage() }}</p>
                                @endif
                            </div>

                            <div class="flex shrink-0 items-center gap-2">
                                @if ($item->review_status === App\Models\BulkGenerationItem::REVIEW_APPROVED)
                                    <span class="rounded-md bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700 ring-1 ring-emerald-200 ring-inset">Approved</span>
                                @elseif ($item->review_status === App\Models\BulkGenerationItem::REVIEW_REJECTED)
                                    <span class="rounded-md bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600">Rejected</span>
                                @elseif ($isPending)
                                    <a href="{{ route('ai.reviews.edit', $item) }}" class="btn btn-secondary">Review</a>
                                @else
                                    <span class="rounded-md bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600">{{ $item->status->label() }}</span>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
            </form>

            @if ($items->hasPages())
                <div class="border-t border-gray-200 px-4 py-3">{{ $items->links() }}</div>
            @endif
        @endif
    </div>
</x-layouts.app>
