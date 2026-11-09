<x-layouts.app title="Sync Content">
    <x-page-header title="Sync Content" :description="$store->name.'. The next step reads the store and shows every change before anything is sent.'" />

    @if ($store->isDemo())
        <x-demo-banner>The preview reads the simulated store. Nothing is sent to a real WooCommerce store.</x-demo-banner>
    @endif

    <form method="POST" action="{{ route('integrations.sync.store', $store) }}" data-single-submit class="max-w-3xl space-y-6">
        @csrf

        @if ($errors->any())
            <div role="alert" class="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-red-200">{{ $errors->first() }}</div>
        @endif

        @if ($unavailableReason)
            <p class="rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-800 ring-1 ring-amber-200">{{ $unavailableReason }}</p>
        @endif

        <section class="card">
            <h2 class="border-b border-gray-200 px-5 py-4 text-sm font-semibold">{{ $links->count() }} selected products</h2>
            <ul class="divide-y divide-gray-100">
                @foreach ($links as $link)
                    <li class="flex items-center justify-between gap-4 px-5 py-2.5 text-sm">
                        <input type="hidden" name="link_ids[]" value="{{ $link->id }}">
                        <span class="min-w-0 truncate">{{ $link->product->original_name }}</span>
                        <span class="shrink-0 text-xs text-gray-500">{{ $link->syncModeLabel() }}</span>
                    </li>
                @endforeach
            </ul>
        </section>

        <section class="card p-5 sm:p-6">
            <label for="language" class="mb-1.5 block text-sm font-medium text-gray-700">Content language</label>
            <select id="language" name="language" class="field" @disabled($languages === [])>
                @foreach ($languages as $language)
                    <option value="{{ $language->value }}">{{ $language->label() }}</option>
                @endforeach
            </select>
            <p class="mt-1.5 text-xs text-gray-500">Only approved content is used: text you wrote, or AI content you approved.</p>
        </section>

        <div class="flex justify-end gap-3">
            <a href="{{ route('integrations.products', $store) }}" class="btn btn-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary" @disabled($languages === [] || $unavailableReason)>Preview changes</button>
        </div>
    </form>
</x-layouts.app>
