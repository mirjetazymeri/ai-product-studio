<x-layouts.app title="Products">
    <x-page-header title="Products" description="Everything in your catalogue.">
        <x-slot:actions>
            <a href="{{ route('catalog.imports.create') }}" class="btn btn-secondary"><x-icon name="upload" class="size-4" /> Import Products</a>
            <a href="{{ route('catalog.exports.create', request()->only(['search', 'status'])) }}" class="btn btn-secondary"><x-icon name="download" class="size-4" /> Export Products</a>
            <a href="{{ route('products.create') }}" class="btn btn-primary"><x-icon name="plus" class="size-4" /> New product</a>
        </x-slot:actions>
    </x-page-header>

    <div class="card">
        <form method="GET" action="{{ route('products.index') }}" class="flex flex-col gap-3 border-b border-gray-200 p-4 sm:flex-row sm:items-center">
            <div class="relative flex-1">
                <x-icon name="search" class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-gray-400" />
                <label for="search" class="sr-only">Search by name or SKU</label>
                <input id="search" name="search" type="search" value="{{ request('search') }}" maxlength="100" placeholder="Search by name or SKU" class="field pl-9">
            </div>

            <div class="grid grid-cols-2 gap-3 sm:flex">
                <div>
                    <label for="status" class="sr-only">Filter by status</label>
                    <select id="status" name="status" data-auto-submit class="field sm:w-40">
                        <option value="">All statuses</option>
                        @foreach ($statuses as $status)
                            <option value="{{ $status->value }}" @selected(request('status') === $status->value)>{{ $status->label() }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="sort" class="sr-only">Sort by creation date</label>
                    <select id="sort" name="sort" data-auto-submit class="field sm:w-40">
                        <option value="newest" @selected(request('sort') !== 'oldest')>Newest first</option>
                        <option value="oldest" @selected(request('sort') === 'oldest')>Oldest first</option>
                    </select>
                </div>
            </div>

            <button type="submit" class="btn btn-secondary">Search</button>
        </form>

        @if ($products->isEmpty())
            @if ($isFiltered)
                <x-empty-state icon="search" title="No matching products" description="Try a different search term or status.">
                    <a href="{{ route('products.index') }}" class="btn btn-secondary">Clear filters</a>
                </x-empty-state>
            @else
                <x-empty-state icon="cube" title="No products yet" description="Add your first product, then write its content in English, Albanian and German.">
                    <a href="{{ route('products.create') }}" class="btn btn-primary"><x-icon name="plus" class="size-4" /> New product</a>
                </x-empty-state>
            @endif
        @else
            {{-- Row checkboxes join this form through their form attribute, so it never nests the delete forms. --}}
            <form id="bulk-form" method="GET" action="{{ route('ai.bulk.create') }}">
                <input type="hidden" name="all_matching" value="" data-all-matching disabled>
                <input type="hidden" name="search" value="{{ request('search') }}">
                <input type="hidden" name="status" value="{{ request('status') }}">
            </form>

            <div data-bulk-bar data-total="{{ $products->total() }}" hidden class="flex flex-wrap items-center gap-3 border-b border-gray-200 bg-indigo-50 px-4 py-2.5 text-sm">
                <span class="font-medium text-indigo-900"><span data-bulk-count>0</span> selected</span>
                <button type="button" data-select-matching hidden class="font-medium text-indigo-700 underline">Select all {{ number_format($products->total()) }} matching</button>
                <div class="ml-auto flex flex-wrap gap-2">
                    <button type="submit" form="bulk-form" formaction="{{ route('catalog.exports.create') }}" class="btn btn-secondary"><x-icon name="download" class="size-4" /> Export selected</button>
                    <button type="submit" form="bulk-form" class="btn btn-primary"><x-icon name="sparkles" class="size-4" /> Generate Content with AI</button>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-gray-200 text-xs font-medium text-gray-500">
                        <tr>
                            <th scope="col" class="w-10 py-3 pl-4">
                                <input type="checkbox" data-select-page aria-label="Select all products on this page" class="size-4 rounded accent-indigo-600">
                            </th>
                            <th scope="col" class="px-4 py-3 font-medium">Product</th>
                            <th scope="col" class="hidden px-4 py-3 font-medium md:table-cell">SKU</th>
                            <th scope="col" class="hidden px-4 py-3 font-medium lg:table-cell">Languages</th>
                            <th scope="col" class="px-4 py-3 font-medium">Status</th>
                            <th scope="col" class="hidden px-4 py-3 font-medium md:table-cell">Created</th>
                            <th scope="col" class="px-4 py-3 text-right font-medium"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($products as $product)
                            <tr class="transition-colors hover:bg-gray-50">
                                <td class="py-3 pl-4">
                                    <input type="checkbox" form="bulk-form" name="product_ids[]" value="{{ $product->id }}" data-product-checkbox aria-label="Select {{ $product->original_name }}" class="size-4 rounded accent-indigo-600">
                                </td>
                                <td class="max-w-64 px-4 py-3">
                                    <a href="{{ route('products.show', $product) }}" class="block truncate font-medium hover:text-indigo-600">{{ $product->original_name }}</a>
                                    @if ($product->category)
                                        <p class="truncate text-xs text-gray-500">{{ $product->category }}</p>
                                    @endif
                                </td>
                                <td class="hidden px-4 py-3 text-gray-500 md:table-cell">{{ $product->sku ?? '—' }}</td>
                                <td class="hidden px-4 py-3 lg:table-cell">
                                    @forelse ($product->contents as $content)
                                        <span class="mr-1 inline-block rounded bg-gray-100 px-1.5 py-0.5 text-xs font-medium text-gray-600 uppercase" title="{{ $content->language->label() }}">{{ $content->language->value }}</span>
                                    @empty
                                        <span class="text-gray-400">—</span>
                                    @endforelse
                                </td>
                                <td class="px-4 py-3"><x-status-badge :status="$product->status" /></td>
                                <td class="hidden px-4 py-3 whitespace-nowrap text-gray-500 md:table-cell">{{ $product->created_at->format('M j, Y') }}</td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center justify-end gap-1">
                                        <a href="{{ route('products.edit', $product) }}" class="rounded-md p-1.5 text-gray-400 transition-colors hover:bg-gray-100 hover:text-gray-700" aria-label="Edit {{ $product->original_name }}" title="Edit">
                                            <x-icon name="pencil" class="size-4" />
                                        </a>
                                        <form method="POST" action="{{ route('products.destroy', $product) }}" data-confirm="This permanently deletes “{{ $product->original_name }}” and all of its content.">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="rounded-md p-1.5 text-gray-400 transition-colors hover:bg-red-50 hover:text-red-600" aria-label="Delete {{ $product->original_name }}" title="Delete">
                                                <x-icon name="trash" class="size-4" />
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($products->hasPages())
                <div class="border-t border-gray-200 px-4 py-3">{{ $products->links() }}</div>
            @endif
        @endif
    </div>
</x-layouts.app>
