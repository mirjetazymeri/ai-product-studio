@php
    $steps = [
        ['Connect the simulated store', 'Done automatically. No address or credentials are needed.', true, null, null],
        ['Import the sample products', $progress['linked'] > 0 ? "{$progress['linked']} products linked." : 'Runs the normal import against the simulated catalog.', $progress['linked'] > 0, route('integrations.imports.create', $store), 'Import Demo Products'],
        ['Create and review content', $progress['pending'] > 0 ? "{$progress['pending']} drafts waiting in Content Review." : 'Generate Sample AI Output, then edit, approve or reject it.', $progress['approved'] > 0, $progress['pending'] > 0 ? route('ai.reviews.index') : null, $progress['pending'] > 0 ? 'Open Content Review' : null],
        ['Preview and run a sync', $progress['approved'] > 0 ? "{$progress['approved']} drafts approved. Select them in Linked Products and choose Sync Content." : 'Compare approved content with the simulated store, field by field.', $progress['synced'] > 0, route('integrations.products', $store), 'Linked Products'],
        ['Inspect the result', 'Open any product below to see its updated values, or read the history.', $progress['synced'] > 0, route('integrations.sync.history'), 'Sync History'],
    ];
@endphp

<x-layouts.app title="WooCommerce Demo">
    <x-page-header title="WooCommerce Demo" description="The complete store integration, running against a simulated store.">
        <x-slot:actions>
            <a href="{{ route('integrations.index') }}" class="btn btn-secondary">Integrations</a>
            <a href="{{ route('integrations.sync.history') }}" class="btn btn-secondary"><x-icon name="clock" class="size-4" /> Sync History</a>
        </x-slot:actions>
    </x-page-header>

    <x-demo-banner>Products, imports and synchronizations on these pages use sample data stored in this app. Nothing is sent to any WooCommerce store.</x-demo-banner>

    @if ($errors->any())
        <div role="alert" class="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-red-200">{{ $errors->first() }}</div>
    @endif

    <div class="grid items-start gap-6 lg:grid-cols-3">
        <section class="card p-5 sm:p-6">
            <h2 class="text-sm font-semibold">Store</h2>
            <dl class="mt-4 space-y-3 text-sm">
                @foreach (['Store Name' => $store->name, 'Platform' => 'WooCommerce', 'Mode' => 'Simulation', 'Connection' => 'Simulated', 'Products' => 'Sample catalog', 'Status' => $store->simulated_outage ? 'Simulated outage' : $store->statusLabel(), 'Linked products' => number_format($progress['linked'])] as $label => $value)
                    <div class="flex justify-between gap-4">
                        <dt class="text-gray-500">{{ $label }}</dt>
                        <dd class="text-right">{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>

            @if ($store->lastErrorMessage())
                <p class="mt-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 ring-1 ring-red-200">Simulated response: {{ $store->lastErrorMessage() }}</p>
            @endif

            <div class="mt-4 flex flex-wrap gap-2 border-t border-gray-100 pt-4">
                <form method="POST" action="{{ route('integrations.demo.test', $store) }}" data-single-submit>
                    @csrf
                    <button type="submit" class="btn btn-secondary">Test Connection</button>
                </form>
                <form method="POST" action="{{ route('integrations.demo.outage', $store) }}" data-single-submit>
                    @csrf
                    <input type="hidden" name="outage" value="{{ $store->simulated_outage ? 0 : 1 }}">
                    <button type="submit" class="btn btn-secondary">{{ $store->simulated_outage ? 'End Simulated Outage' : 'Simulate Outage' }}</button>
                </form>
            </div>
        </section>

        <section class="card lg:col-span-2">
            <h2 class="border-b border-gray-200 px-5 py-4 text-sm font-semibold">Walkthrough</h2>
            <ol class="divide-y divide-gray-100">
                @foreach ($steps as [$title, $description, $isDone, $url, $action])
                    <li class="flex flex-col gap-3 px-5 py-3 sm:flex-row sm:items-center sm:justify-between">
                        <div class="flex min-w-0 items-start gap-3">
                            <span @class([
                                'mt-0.5 grid size-5 shrink-0 place-items-center rounded-full text-xs font-semibold',
                                'bg-emerald-100 text-emerald-700' => $isDone,
                                'bg-gray-100 text-gray-500' => ! $isDone,
                            ])>
                                @if ($isDone)
                                    <x-icon name="check" class="size-3.5" />
                                    <span class="sr-only">Done</span>
                                @else
                                    {{ $loop->iteration }}
                                @endif
                            </span>
                            <div class="min-w-0">
                                <p class="text-sm font-medium">{{ $title }}</p>
                                <p class="mt-0.5 text-sm text-gray-500">{{ $description }}</p>
                            </div>
                        </div>
                        @if ($action)
                            <a href="{{ $url }}" class="btn btn-secondary shrink-0 self-start sm:self-auto">{{ $action }}</a>
                        @elseif ($loop->iteration === 3 && $progress['linked'] > 0)
                            <form method="POST" action="{{ route('integrations.demo.sample', $store) }}" data-single-submit class="shrink-0">
                                @csrf
                                <button type="submit" class="btn btn-primary"><x-icon name="sparkles" class="size-4" /> Generate Sample AI Output</button>
                            </form>
                        @endif
                    </li>
                @endforeach
            </ol>
        </section>
    </div>

    <section class="card p-5 sm:p-6">
        <h2 class="text-sm font-semibold">What a sync can change</h2>
        <p class="mt-1 text-sm text-gray-500">Only the title, long description, short description and URL slug can be sent. Prices, stock quantities, SKU, images, status, variations, shipping and tax are outside the allowlist, and each product page below compares them with the original catalog.</p>
    </section>

    <section class="card">
        <div class="border-b border-gray-200 px-5 py-4">
            <h2 class="text-sm font-semibold">Simulated store catalog</h2>
            <p class="mt-0.5 text-xs text-gray-500">The current remote values, as the store would report them.</p>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-gray-200 text-xs text-gray-500">
                    <tr>
                        <th scope="col" class="px-5 py-3 font-medium">Product</th>
                        <th scope="col" class="px-4 py-3 font-medium">Store ID</th>
                        <th scope="col" class="hidden px-4 py-3 font-medium md:table-cell">Status</th>
                        <th scope="col" class="hidden px-4 py-3 font-medium md:table-cell">Price</th>
                        <th scope="col" class="hidden px-4 py-3 font-medium lg:table-cell">Stock</th>
                        <th scope="col" class="hidden px-4 py-3 font-medium lg:table-cell">Modified (GMT)</th>
                        <th scope="col" class="px-4 py-3 font-medium">Link</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach ($records as $record)
                        @php($product = $record->payload)
                        <tr class="transition-colors hover:bg-gray-50">
                            <td class="max-w-64 px-5 py-3">
                                <a href="{{ route('integrations.demo.product', [$store, $record->remote_id]) }}" class="block truncate font-medium hover:text-indigo-600">{{ $product['name'] ?: 'Record with no name' }}</a>
                                <p class="truncate text-xs text-gray-500">{{ ucfirst($product['type']) }} · {{ $product['sku'] ? 'SKU '.$product['sku'] : 'No SKU' }}</p>
                            </td>
                            <td class="px-4 py-3 text-gray-500 tabular-nums">#{{ $record->remote_id }}</td>
                            <td class="hidden px-4 py-3 text-gray-500 md:table-cell">{{ ucfirst($product['status']) }}</td>
                            <td class="hidden px-4 py-3 text-gray-500 tabular-nums md:table-cell">{{ filled($product['price']) ? '$'.$product['price'] : '—' }}</td>
                            <td class="hidden px-4 py-3 text-gray-500 tabular-nums lg:table-cell">{{ $product['stock_quantity'] ?? '—' }}</td>
                            <td class="hidden px-4 py-3 whitespace-nowrap text-gray-500 tabular-nums lg:table-cell">{{ str_replace('T', ' ', $product['date_modified_gmt']) }}</td>
                            <td class="px-4 py-3 whitespace-nowrap text-gray-500">{{ $links->has($record->remote_id) ? 'Linked' : 'Not imported' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <section class="card p-5 sm:p-6">
        <h2 class="text-sm font-semibold">Reset Demo Store</h2>
        <p class="mt-1 text-sm text-gray-500">Restores the sample catalog and removes demo products, their content, drafts, import records and sync history. Real stores, real products and settings aren't touched. Real Gemini requests stay in Generation History, so quota isn't refunded.</p>
        <details class="mt-3">
            <summary class="btn btn-danger-soft">Reset Demo Store</summary>
            <form method="POST" action="{{ route('integrations.demo.reset', $store) }}" data-single-submit class="mt-3 max-w-sm space-y-3 rounded-lg bg-red-50 p-3">
                @csrf
                <label class="flex items-start gap-2 text-sm text-red-800">
                    <input type="checkbox" name="confirm" value="1" required class="mt-0.5 size-4 rounded accent-red-600">
                    Delete all demo activity and restore the sample catalog
                </label>
                <button type="submit" class="btn btn-danger">Reset demo</button>
            </form>
        </details>
    </section>
</x-layouts.app>
