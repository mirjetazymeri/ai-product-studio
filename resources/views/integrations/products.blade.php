<x-layouts.app title="Linked Products">
    <x-page-header title="Linked Products" :description="$store->name">
        <x-slot:actions>
            <a href="{{ $store->isDemo() ? route('integrations.demo.show', $store) : route('integrations.index') }}" class="btn btn-secondary">{{ $store->isDemo() ? 'WooCommerce Demo' : 'Integrations' }}</a>
            @unless ($store->isDisconnected())
                <a href="{{ route('integrations.imports.create', $store) }}" class="btn btn-secondary"><x-icon name="download" class="size-4" /> {{ $store->isDemo() ? 'Import Demo Products' : 'Import Products' }}</a>
            @endunless
            @if ($store->isDemo() && $links->isNotEmpty())
                <form method="POST" action="{{ route('integrations.demo.sample', $store) }}" data-single-submit>
                    @csrf
                    <button type="submit" class="btn btn-secondary"><x-icon name="sparkles" class="size-4" /> Generate Sample AI Output</button>
                </form>
            @endif
        </x-slot:actions>
    </x-page-header>

    @if ($store->isDemo())
        <x-demo-banner>These products came from the simulated store and are kept apart from your real catalog.</x-demo-banner>
    @endif

    @if ($errors->any())
        <div role="alert" class="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-red-200">{{ $errors->first() }}</div>
    @endif

    @if ($unavailableReason)
        <p class="rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-800 ring-1 ring-amber-200">Synchronization is unavailable. {{ $unavailableReason }}</p>
    @endif

    <div class="card">
        @if ($links->isEmpty())
            <x-empty-state icon="cube" title="No linked products" description="Import products from this store to link them here." />
        @else
            <form id="bulk-form" method="GET" action="{{ route('integrations.sync.create', $store) }}">
                <input type="hidden" data-all-matching disabled>
            </form>

            <div data-bulk-bar data-total="{{ $links->count() }}" hidden class="flex flex-wrap items-center gap-3 border-b border-gray-200 bg-indigo-50 px-4 py-2.5 text-sm">
                <span class="font-medium text-indigo-900"><span data-bulk-count>0</span> selected</span>
                <button type="button" data-select-matching hidden></button>
                @unless ($unavailableReason)
                    <button type="submit" form="bulk-form" class="btn btn-primary ml-auto">Sync Content</button>
                @endunless
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-gray-200 text-xs text-gray-500">
                        <tr>
                            <th scope="col" class="w-10 py-3 pl-4"><input type="checkbox" data-select-page aria-label="Select all on this page" class="size-4 rounded accent-indigo-600"></th>
                            <th scope="col" class="px-4 py-3 font-medium">Product</th>
                            <th scope="col" class="hidden px-4 py-3 font-medium md:table-cell">Store ID</th>
                            <th scope="col" class="px-4 py-3 font-medium">Sync</th>
                            <th scope="col" class="hidden px-4 py-3 font-medium lg:table-cell">Approved content</th>
                            <th scope="col" class="hidden px-4 py-3 font-medium lg:table-cell">Last sync</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($links as $link)
                            <tr class="transition-colors hover:bg-gray-50">
                                <td class="py-3 pl-4">
                                    <input type="checkbox" form="bulk-form" name="link_ids[]" value="{{ $link->id }}" @unless ($link->isReadOnly()) data-product-checkbox @endunless @disabled($link->isReadOnly()) aria-label="Select {{ $link->product->original_name }}" class="size-4 rounded accent-indigo-600">
                                </td>
                                <td class="max-w-64 px-4 py-3">
                                    <a href="{{ $store->isDemo() ? route('integrations.demo.product', [$store, $link->remote_id]) : route('products.show', $link->product) }}" class="block truncate font-medium hover:text-indigo-600">{{ $link->product->original_name }}</a>
                                    <p class="truncate text-xs text-gray-500">{{ ucfirst($link->remote_type) }} @if ($link->remote_sku) · SKU {{ $link->remote_sku }} @endif</p>
                                </td>
                                <td class="hidden px-4 py-3 text-gray-500 tabular-nums md:table-cell">#{{ $link->remote_id }}</td>
                                <td class="px-4 py-3 whitespace-nowrap text-gray-500">{{ $link->syncModeLabel() }}</td>
                                <td class="hidden px-4 py-3 lg:table-cell">
                                    @forelse ($link->product->contents->filter(fn ($content) => $content->source->isApprovedForSync()) as $content)
                                        <span class="mr-1 inline-block rounded bg-gray-100 px-1.5 py-0.5 text-xs font-medium text-gray-600 uppercase">{{ $content->language->value }}</span>
                                    @empty
                                        <span class="text-gray-400">None yet</span>
                                    @endforelse
                                </td>
                                <td class="hidden px-4 py-3 whitespace-nowrap text-gray-500 lg:table-cell">{{ $link->last_synced_at?->format('M j, Y') ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($links->hasPages())
                <div class="border-t border-gray-200 px-4 py-3">{{ $links->links() }}</div>
            @endif
        @endif
    </div>
</x-layouts.app>
