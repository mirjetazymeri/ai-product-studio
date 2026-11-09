<x-layouts.app title="Generate Content with AI">
    <x-page-header title="Generate Content with AI" description="Nothing is saved or published. Every result waits for your review." />

    <form method="POST" action="{{ route('ai.bulk.store') }}" data-single-submit data-bulk-estimate data-remaining="{{ $remaining }}" class="max-w-3xl space-y-6">
        @csrf
        <input type="hidden" name="submission_token" value="{{ old('submission_token', $token) }}">

        @if ($errors->any())
            <div role="alert" class="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-red-200">{{ $errors->first() }}</div>
        @endif

        @if ($wasTruncated)
            <p class="rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-800 ring-1 ring-amber-200">Only the first {{ $limit }} products are shown, because that's the most one batch can include.</p>
        @endif

        <section class="card">
            <h2 class="border-b border-gray-200 px-5 py-4 text-sm font-semibold">Selected products</h2>
            <ul class="divide-y divide-gray-100">
                @foreach ($products as $product)
                    @php($eligible = $canGenerate($product))
                    <li class="flex items-center gap-3 px-5 py-2.5 text-sm">
                        <input type="checkbox" id="product-{{ $product->id }}" name="product_ids[]" value="{{ $product->id }}" data-estimate-product class="size-4 rounded accent-indigo-600" @checked($eligible) @disabled(! $eligible)>
                        <label for="product-{{ $product->id }}" class="min-w-0 flex-1 truncate">{{ $product->original_name }}</label>
                        @unless ($eligible)
                            <span class="shrink-0 text-xs text-amber-700">Needs a category, material or details</span>
                        @endunless
                    </li>
                @endforeach
            </ul>
        </section>

        <section class="card space-y-4 p-5 sm:p-6">
            <fieldset>
                <legend class="mb-2 text-sm font-medium text-gray-700">Target languages</legend>
                <div class="flex flex-wrap gap-6">
                    @foreach ($languages as $language)
                        <label class="flex items-center gap-2 text-sm">
                            <input type="checkbox" name="languages[]" value="{{ $language->value }}" data-estimate-language class="size-4 rounded accent-indigo-600" @checked(in_array($language->value, old('languages', ['en']), true))>
                            {{ $language->label() }}
                        </label>
                    @endforeach
                </div>
            </fieldset>

            @include('ai.partials.advanced-options')
        </section>

        <section class="card space-y-2 p-5 sm:p-6">
            <h2 class="text-sm font-semibold">Before you start</h2>
            <dl class="space-y-1.5 text-sm">
                <div class="flex justify-between gap-4"><dt class="text-gray-500">Selected products</dt><dd data-estimate-products class="tabular-nums">0</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-gray-500">Target languages</dt><dd data-estimate-languages class="tabular-nums">0</dd></div>
                <div class="flex justify-between gap-4 font-medium"><dt>Estimated AI requests</dt><dd data-estimate-requests class="tabular-nums">0</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-gray-500">Generations left today</dt><dd class="tabular-nums">{{ number_format($remaining) }}</dd></div>
            </dl>
            <p class="text-xs text-gray-500">Actual token usage varies with each product. Requests run at up to {{ $perMinute }} per minute, so large batches take time. Products that already have content in a language keep it unless you later approve a replacement.</p>
            <p data-estimate-warning hidden class="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800 ring-1 ring-amber-200">This is more than today's allowance. Deselect some products or languages to continue.</p>

            <label class="flex items-start gap-2 pt-2 text-sm">
                <input type="checkbox" name="confirm" value="1" required class="mt-0.5 size-4 rounded accent-indigo-600">
                I want to start this batch of AI requests.
            </label>
        </section>

        <div class="flex justify-end gap-3">
            <a href="{{ route('products.index') }}" class="btn btn-secondary">Cancel</a>
            <button type="submit" data-estimate-submit class="btn btn-primary"><x-icon name="sparkles" class="size-4" /> Start generation</button>
        </div>
    </form>
</x-layouts.app>
