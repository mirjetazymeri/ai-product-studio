@php($status = $transfer->status)

<x-layouts.app title="WooCommerce Import">
    <x-page-header title="WooCommerce import" :description="$store->name">
        <x-slot:actions>
            <x-transfer-status :status="$status" />
        </x-slot:actions>
    </x-page-header>

    @if ($store->isDemo())
        <x-demo-banner>This import read the simulated sample catalog. Imported products stay separate from your real catalog.</x-demo-banner>
    @endif

    @if ($errors->any())
        <div role="alert" class="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-red-200">{{ $errors->first() }}</div>
    @endif

    @if ($status->isRunning())
        <section class="card p-5" data-auto-refresh="3000">
            <p class="text-sm font-medium">{{ ($transfer->options['phase'] ?? '') === 'fetching' ? 'Reading products from the store' : 'Saving products' }}</p>
            @if (($transfer->options['phase'] ?? '') === 'fetching')
                <p class="mt-1 text-sm text-gray-500">On page {{ $transfer->options['next_page'] }} of the store's product list.</p>
            @endif
            <p class="mt-2 text-sm text-gray-500">Larger stores continue in the background. If nothing changes, make sure the queue worker is running: <code class="font-mono text-xs">php artisan queue:work</code></p>
        </section>
    @endif

    @if ($status === App\Enums\CatalogTransferStatus::Failed)
        <section class="card flex flex-col gap-4 p-5 sm:flex-row sm:items-center sm:justify-between">
            <p class="text-sm text-red-700">{{ $transfer->error_summary }}</p>
            <form method="POST" action="{{ route('integrations.imports.retry', $transfer) }}" data-single-submit>
                @csrf
                <button type="submit" class="btn btn-primary">Continue import</button>
            </form>
        </section>
    @endif

    @if ($status === App\Enums\CatalogTransferStatus::Validated)
        <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4" aria-label="Summary">
            <x-stat-card label="New products" :value="$summary['new'] ?? 0" icon="plus" />
            <x-stat-card label="Already linked" :value="$summary['linked'] ?? 0" icon="check" />
            <x-stat-card label="Possible matches" :value="$summary['possible_duplicates'] ?? 0" icon="search" />
            <x-stat-card label="Couldn't be read" :value="$summary['invalid_rows'] ?? 0" icon="close" />
        </section>

        <form method="POST" action="{{ route('integrations.imports.confirm', $transfer) }}" data-single-submit class="space-y-6">
            @csrf

            <section class="card space-y-3 p-5 sm:p-6">
                <h2 class="text-sm font-semibold">New products</h2>
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" name="import_new" value="1" checked class="size-4 rounded accent-indigo-600">
                    Import {{ number_format($summary['new'] ?? 0) }} new products
                </label>
                @if ($newRows->isNotEmpty())
                    <ul class="space-y-1 text-sm text-gray-600">
                        @foreach ($newRows as $row)
                            <li class="truncate">{{ $row->payload['remote']['name'] }} <span class="text-gray-400">· {{ $row->payload['remote']['type'] }} @if ($store->isDemo()) · #{{ $row->payload['remote']['id'] }} @endif</span></li>
                        @endforeach
                    </ul>
                @endif
            </section>

            @if ($matches->isNotEmpty())
                <section class="card">
                    <div class="border-b border-gray-200 px-5 py-4">
                        <h2 class="text-sm font-semibold">Possible matches</h2>
                        <p class="mt-0.5 text-xs text-gray-500">These share a SKU with a local product or another store product. Nothing is merged unless you choose it here.</p>
                    </div>
                    <ul class="divide-y divide-gray-100">
                        @foreach ($matches as $row)
                            @php($local = $localProducts->get($row->payload['local_product_id'] ?? 0))
                            @php($isAmbiguous = $row->match_type === App\Services\WooCommerce\WooCommerceProductImporter::AMBIGUOUS)
                            <li class="grid gap-2 px-5 py-3 sm:grid-cols-2 sm:items-center sm:gap-6">
                                <div class="min-w-0 text-sm">
                                    <p class="truncate font-medium">{{ $row->payload['remote']['name'] }}</p>
                                    <p class="truncate text-xs text-gray-500">
                                        SKU {{ $row->payload['remote']['sku'] }}
                                        @if ($isAmbiguous)
                                            · Ambiguous: this SKU can't be matched safely
                                        @elseif ($local)
                                            · Local: {{ $local->original_name }}
                                        @endif
                                    </p>
                                </div>
                                <div>
                                    <label for="decision-{{ $row->id }}" class="sr-only">Action for {{ $row->payload['remote']['name'] }}</label>
                                    <select id="decision-{{ $row->id }}" name="decisions[{{ $row->id }}]" class="field">
                                        <option value="skip">Skip for now</option>
                                        @unless ($isAmbiguous)
                                            <option value="link">Link to this local product</option>
                                        @endunless
                                        <option value="new">Import as a separate product</option>
                                    </select>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                    @if (($summary['possible_duplicates'] ?? 0) > $maxMatches)
                        <p class="border-t border-gray-200 px-5 py-3 text-xs text-gray-500">Only the first {{ $maxMatches }} are shown. The rest are skipped, and you can import again to review them.</p>
                    @endif
                </section>
            @endif

            @if ($linkedRows->isNotEmpty())
                <section class="card space-y-3 p-5 sm:p-6">
                    <h2 class="text-sm font-semibold">Already linked products</h2>
                    <ul class="space-y-1 text-sm text-gray-600">
                        @foreach ($linkedRows as $row)
                            <li class="truncate">{{ $row->payload['remote']['name'] }}: <span class="text-gray-500">{{ $row->payload['changes'] === [] ? 'no differences' : 'may change '.implode(', ', $row->payload['changes']) }}</span></li>
                        @endforeach
                    </ul>
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" name="refresh_linked" value="1" class="size-4 rounded accent-indigo-600">
                        Refresh linked products from the store
                    </label>
                    <div class="grid gap-2 sm:grid-cols-2">
                        @foreach ($refreshableFields as $field => $label)
                            <label class="flex items-center gap-2 text-sm text-gray-600">
                                <input type="checkbox" name="refresh_fields[]" value="{{ $field }}" class="size-4 rounded accent-indigo-600">
                                {{ $label }}
                            </label>
                        @endforeach
                    </div>
                    <label class="flex items-start gap-2 text-sm text-amber-800">
                        <input type="checkbox" name="confirm_refresh" value="1" class="mt-0.5 size-4 rounded accent-indigo-600">
                        I understand the selected local fields will be replaced. AI-generated and edited content is never overwritten.
                    </label>
                </section>
            @endif

            <div class="flex justify-end gap-3">
                <a href="{{ route('integrations.index') }}" class="btn btn-secondary">Cancel</a>
                <button type="submit" class="btn btn-primary">Confirm import</button>
            </div>
        </form>
    @endif

    @if ($status->isFinished() && $status !== App\Enums\CatalogTransferStatus::Failed)
        <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4" aria-label="Results">
            <x-stat-card label="Created" :value="$summary['created'] ?? 0" icon="plus" />
            <x-stat-card label="Linked or refreshed" :value="$summary['updated'] ?? 0" icon="pencil" />
            <x-stat-card label="Skipped" :value="$summary['skipped'] ?? 0" icon="eye" />
            <x-stat-card label="Errors" :value="$transfer->failed_records" icon="close" />
        </section>

        @if ($demoResults->isNotEmpty())
            <section class="card">
                <h2 class="border-b border-gray-200 px-5 py-4 text-sm font-semibold">Import results</h2>
                <ul class="divide-y divide-gray-100">
                    @foreach ($demoResults as $row)
                        <li class="flex items-center justify-between gap-4 px-5 py-2.5 text-sm">
                            <span class="min-w-0">
                                <span class="block truncate font-medium">{{ $row->payload['remote']['name'] }}</span>
                                <span class="block truncate text-xs text-gray-500">Store ID #{{ $row->payload['remote']['id'] }} @if ($row->result_message) · {{ $row->result_message }} @endif</span>
                            </span>
                            <span class="shrink-0 rounded-md bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600">{{ ucfirst((string) $row->result) }}</span>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        <div class="flex flex-wrap gap-3">
            <a href="{{ route('integrations.products', $store) }}" class="btn btn-primary">View linked products</a>
            @if ($store->isDemo())
                <a href="{{ route('integrations.demo.show', $store) }}" class="btn btn-secondary">WooCommerce Demo</a>
            @else
                <a href="{{ route('products.index') }}" class="btn btn-secondary">All products</a>
            @endif
        </div>
    @endif

    @if ($problemRows->isNotEmpty())
        <section class="card">
            <h2 class="border-b border-gray-200 px-5 py-4 text-sm font-semibold">Problems</h2>
            <ul class="divide-y divide-gray-100">
                @foreach ($problemRows as $row)
                    <li class="px-5 py-3 text-sm">
                        <span class="font-medium">{{ $row->payload['product']['original_name'] ?? 'Unnamed product' }}</span>
                        <p class="mt-0.5 text-red-700">{{ implode(' ', $row->errors ?? [$row->result_message]) }}</p>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
</x-layouts.app>
