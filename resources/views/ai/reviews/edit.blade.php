@php
    $value = fn (string $field) => old($field, $content[$field] ?? null);
    $keywords = $value('keywords');
@endphp

<x-layouts.app title="Review Content">
    <a href="{{ route('ai.reviews.index') }}" class="inline-flex items-center gap-1.5 text-sm font-medium text-gray-500 hover:text-gray-900">
        <x-icon name="arrow-left" class="size-4" /> Content Review
    </a>

    <x-page-header :title="$item->product->original_name" :description="$item->language->label().' draft. Approving saves it to the product but doesn\'t publish anything.'" />

    @if ($item->product->isDemo())
        <x-demo-banner>{{ $item->generation?->isSample() ? 'Sample AI Output: fixed example text that wasn\'t written by Gemini and used no tokens.' : 'This draft came from a real Gemini request you started for a demo product.' }}</x-demo-banner>
    @endif

    <div class="grid items-start gap-6 lg:grid-cols-3">
        <form method="POST" action="{{ route('ai.reviews.update', $item) }}" data-single-submit class="card space-y-4 p-5 sm:p-6 lg:col-span-2">
            @csrf
            @method('PUT')

            <x-form.input name="title" label="Title" :value="$value('title')" required maxlength="255" />
            <x-form.textarea name="short_description" label="Short description" :value="$value('short_description')" rows="2" required maxlength="1000" data-autosize class="resize-none overflow-hidden" />
            <x-form.textarea name="long_description" label="Long description" :value="$value('long_description')" rows="8" required maxlength="20000" data-autosize class="resize-none overflow-hidden" />
            <x-form.input name="meta_title" label="Meta title" :value="$value('meta_title')" required maxlength="255" />
            <x-form.textarea name="meta_description" label="Meta description" :value="$value('meta_description')" rows="2" required maxlength="500" data-autosize class="resize-none overflow-hidden" />
            <x-form.input name="slug" label="URL slug" :value="$value('slug')" required maxlength="255" />
            <x-form.input name="keywords" label="Keywords" :value="is_array($keywords) ? implode(', ', $keywords) : $keywords" required hint="Separate keywords with commas." error-key="keywords*" />

            @if ($existing)
                <label class="flex items-start gap-2 rounded-lg bg-amber-50 px-3 py-2.5 text-sm text-amber-800 ring-1 ring-amber-200">
                    <input type="checkbox" name="confirm_overwrite" value="1" class="mt-0.5 size-4 rounded accent-indigo-600">
                    <span>Replace the existing {{ $item->language->label() }} content. This can't be undone.</span>
                </label>
                @error('confirm_overwrite')
                    <p class="text-sm text-red-600">{{ $message }}</p>
                @enderror
            @endif

            <div class="flex flex-wrap justify-end gap-3">
                <button type="submit" name="action" value="save" formnovalidate class="btn btn-secondary">Save edits</button>
                <button type="submit" name="action" value="approve" class="btn btn-primary">Approve</button>
            </div>
        </form>

        <div class="space-y-6">
            @if ($item->product->isDemo())
                <section class="card flex flex-wrap items-center justify-between gap-3 p-5">
                    <h2 class="text-sm font-semibold">Not right?</h2>
                    <div class="flex gap-2">
                        <a href="{{ route('ai.reviews.index') }}" class="btn btn-secondary">Skip for now</a>
                        <form method="POST" action="{{ route('ai.reviews.reject', $item) }}" data-single-submit>
                            @csrf
                            <button type="submit" class="btn btn-danger-soft">Reject</button>
                        </form>
                    </div>
                </section>
            @endif

            <section class="card p-5">
                <div class="flex items-center justify-between gap-4">
                    <h2 class="text-sm font-semibold">SEO Quality</h2>
                    <p class="text-sm font-semibold tabular-nums">{{ $review['seo']['score'] }}/100</p>
                </div>
                <ul class="mt-3 list-disc space-y-1 pl-4 text-sm text-gray-700">
                    @forelse ($review['seo']['suggestions'] as $suggestion)
                        <li>{{ $suggestion }}</li>
                    @empty
                        <li>No suggestions.</li>
                    @endforelse
                </ul>
                <p class="mt-2 text-xs text-gray-500">A checklist of common recommendations. It doesn't predict search rankings.</p>
            </section>

            @if ($review['quality'] !== [])
                <section class="rounded-xl bg-amber-50 p-5 ring-1 ring-amber-200">
                    <h2 class="text-sm font-semibold text-amber-900">Please review</h2>
                    <ul class="mt-2 list-disc space-y-1 pl-4 text-sm text-amber-900">
                        @foreach ($review['quality'] as $issue)
                            <li>{{ $issue['message'] }}</li>
                        @endforeach
                    </ul>
                </section>
            @endif

            @if ($existing)
                <section class="card p-5">
                    <h2 class="text-sm font-semibold">Saved content</h2>
                    <p class="mt-2 text-sm font-medium break-words">{{ $existing->title ?? 'Untitled' }}</p>
                    <p class="mt-1 text-sm break-words whitespace-pre-line text-gray-600">{{ $existing->short_description }}</p>
                </section>
            @endif
        </div>
    </div>
</x-layouts.app>
