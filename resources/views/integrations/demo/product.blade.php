@php
    use App\Models\BulkGenerationItem;

    $remote = $record->payload;
    $seo = collect($remote['meta_data'])->pluck('value', 'key');
    $reviewLabel = match ($latestReview?->review_status) {
        BulkGenerationItem::REVIEW_PENDING => 'Waiting for review',
        BulkGenerationItem::REVIEW_APPROVED => 'Approved',
        BulkGenerationItem::REVIEW_REJECTED => 'Rejected',
        default => 'No draft yet',
    };
@endphp

<x-layouts.app :title="($remote['name'] ?: 'Demo product')">
    <a href="{{ route('integrations.demo.show', $store) }}" class="inline-flex items-center gap-1.5 text-sm font-medium text-gray-500 hover:text-gray-900">
        <x-icon name="arrow-left" class="size-4" /> WooCommerce Demo
    </a>

    <x-page-header :title="$remote['name'] ?: 'Record with no name'" :description="'Simulated store product #'.$record->remote_id" />

    <x-demo-banner>These are the values stored for the simulated store. No real WooCommerce product exists behind this page.</x-demo-banner>

    @if ($errors->any())
        <div role="alert" class="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-red-200">{{ $errors->first() }}</div>
    @endif

    <div class="grid items-start gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <section class="card">
                <div class="border-b border-gray-200 px-5 py-4">
                    <h2 class="text-sm font-semibold">Fields a sync can update</h2>
                    <p class="mt-0.5 text-xs text-gray-500">Last modified {{ str_replace('T', ' ', $remote['date_modified_gmt']) }} GMT</p>
                </div>
                <dl class="divide-y divide-gray-100">
                    @foreach ($descriptive as $label => $value)
                        <div class="px-5 py-3">
                            <dt class="text-xs font-medium text-gray-500">{{ $label }}</dt>
                            <dd class="mt-0.5 text-sm break-words whitespace-pre-line">{{ $value ?: 'Empty' }}</dd>
                        </div>
                    @endforeach
                </dl>
            </section>

            <section class="card">
                <div class="border-b border-gray-200 px-5 py-4">
                    <h2 class="text-sm font-semibold">Protected fields</h2>
                    <p class="mt-0.5 text-xs text-gray-500">Compared with the original catalog record each time this page opens. A content sync can't change these.</p>
                </div>
                <ul class="grid sm:grid-cols-2">
                    @foreach ($protected as $field)
                        <li class="flex items-center justify-between gap-3 border-b border-gray-100 px-5 py-2.5 text-sm">
                            <span class="min-w-0">
                                <span class="block font-medium">{{ $field['label'] }}</span>
                                <span class="block truncate text-xs text-gray-500">{{ $field['value'] }}</span>
                            </span>
                            <span @class([
                                'shrink-0 rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset',
                                'bg-emerald-50 text-emerald-700 ring-emerald-200' => $field['unchanged'],
                                'bg-red-50 text-red-700 ring-red-200' => ! $field['unchanged'],
                            ])>{{ $field['unchanged'] ? 'Unchanged' : 'Changed' }}</span>
                        </li>
                    @endforeach
                </ul>
            </section>
        </div>

        <div class="space-y-6">
            <section class="card p-5">
                <h2 class="text-sm font-semibold">Store record</h2>
                <dl class="mt-4 space-y-3 text-sm">
                    @foreach (['Type' => ucfirst($remote['type']), 'Category' => $remote['categories'][0]['name'] ?? '—', 'Variations' => count($remote['variations']), 'Link' => $link?->syncModeLabel() ?? 'Not imported'] as $label => $value)
                        <div class="flex justify-between gap-4">
                            <dt class="text-gray-500">{{ $label }}</dt>
                            <dd class="min-w-0 text-right break-words">{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>
                @if ($seo->isNotEmpty())
                    <div class="mt-4 border-t border-gray-100 pt-4 text-sm">
                        <h3 class="text-xs font-medium text-gray-500">Existing SEO metadata (read-only)</h3>
                        <p class="mt-1 break-words">{{ $seo['_yoast_wpseo_title'] ?? '' }}</p>
                        <p class="mt-1 break-words text-gray-500">{{ $seo['_yoast_wpseo_metadesc'] ?? '' }}</p>
                    </div>
                @endif
                @if ($link)
                    <a href="{{ route('products.show', $link->product) }}" class="btn btn-secondary mt-4 w-full">Open local product</a>
                @endif
            </section>

            @if ($link === null)
                <section class="card p-5">
                    <h2 class="text-sm font-semibold">Not imported</h2>
                    <p class="mt-1 text-sm text-gray-500">{{ filled($remote['name']) ? 'Import the sample products to create content for this one.' : 'This record has no name, so the import reports it as an error.' }}</p>
                </section>
            @elseif ($link->isReadOnly())
                <section class="card p-5">
                    <h2 class="text-sm font-semibold">Read-only product</h2>
                    <p class="mt-1 text-sm text-gray-500">Products of type "{{ $link->remote_type }}" can be imported but not synchronized, exactly as with a real store.</p>
                </section>
            @else
                <section class="card p-5">
                    <div class="flex items-center justify-between gap-3">
                        <h2 class="text-sm font-semibold">Sample AI Output</h2>
                        <span class="rounded-md bg-gray-100 px-2 py-0.5 text-xs font-medium whitespace-nowrap text-gray-600">{{ $reviewLabel }}</span>
                    </div>
                    <p class="mt-1 text-sm text-gray-500">Fixed example text for this demo. It isn't written by Gemini and uses no tokens or quota.</p>
                    @if ($latestReview?->generation)
                        <p class="mt-2 text-xs text-gray-500">Latest draft: {{ $latestReview->generation->isSample() ? 'Sample AI Output' : 'Real Gemini' }}</p>
                    @endif
                    @if ($approvedContent)
                        <p class="mt-2 text-xs text-gray-500">Approved content: {{ $approvedContent->source->label() }}</p>
                    @endif
                    <form method="POST" action="{{ route('integrations.demo.sample', $store) }}" data-single-submit class="mt-4">
                        @csrf
                        <input type="hidden" name="remote_id" value="{{ $record->remote_id }}">
                        <button type="submit" class="btn btn-primary w-full"><x-icon name="sparkles" class="size-4" /> Generate Sample AI Output</button>
                    </form>
                </section>

                <section class="card p-5">
                    <h2 class="text-sm font-semibold">Real Gemini AI</h2>
                    <p class="mt-1 text-sm text-gray-500">Optional. This sends a real request to Gemini, uses tokens, and counts towards your daily allowance ({{ number_format($remainingToday) }} left today).</p>
                    <form method="POST" action="{{ route('integrations.demo.real', [$store, $record->remote_id]) }}" data-single-submit class="mt-3 space-y-3">
                        @csrf
                        <label class="flex items-start gap-2 text-sm">
                            <input type="checkbox" name="confirm_real_ai" value="1" required class="mt-0.5 size-4 rounded accent-indigo-600">
                            Send a real request to Gemini for this sample product
                        </label>
                        <button type="submit" class="btn btn-secondary w-full">Generate with Real Gemini</button>
                    </form>
                </section>
            @endif
        </div>
    </div>
</x-layouts.app>
