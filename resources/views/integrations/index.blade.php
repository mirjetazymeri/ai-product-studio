<x-layouts.app title="Integrations">
    <x-page-header title="Integrations" description="Connect stores, import products, and send approved content back.">
        <x-slot:actions>
            <a href="{{ route('integrations.sync.history') }}" class="btn btn-secondary"><x-icon name="clock" class="size-4" /> Sync History</a>
            @unless ($stores->contains(fn ($store) => $store->isDemo()))
                <form method="POST" action="{{ route('integrations.demo.activate') }}" data-single-submit>
                    @csrf
                    <button type="submit" class="btn btn-secondary"><x-icon name="sparkles" class="size-4" /> Try WooCommerce Demo</button>
                </form>
            @endunless
            <a href="{{ route('integrations.create') }}" class="btn btn-primary"><x-icon name="plus" class="size-4" /> Connect WooCommerce Store</a>
        </x-slot:actions>
    </x-page-header>

    @if ($errors->any())
        <div role="alert" class="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-red-200">{{ $errors->first() }}</div>
    @endif

    @if ($stores->isEmpty())
        <div class="card">
            <x-empty-state icon="cube" title="No stores connected" description="Connect a WooCommerce store to import its products. Nothing changes in your store until you review and confirm it.">
                <div class="flex flex-wrap justify-center gap-3">
                    <a href="{{ route('integrations.create') }}" class="btn btn-primary"><x-icon name="plus" class="size-4" /> Connect WooCommerce Store</a>
                    <form method="POST" action="{{ route('integrations.demo.activate') }}" data-single-submit>
                        @csrf
                        <button type="submit" class="btn btn-secondary">Try WooCommerce Demo</button>
                    </form>
                </div>
            </x-empty-state>
        </div>
    @else
        <div class="space-y-4">
            @foreach ($stores as $store)
                @if ($store->isDemo())
                    <section class="card p-5 sm:p-6">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                            <div class="min-w-0">
                                <h2 class="truncate text-base font-semibold">{{ $store->name }}</h2>
                                <p class="text-sm text-amber-800">Demo Mode — No Real WooCommerce Connection</p>
                            </div>
                            <span class="self-start rounded-md bg-amber-50 px-2 py-0.5 text-xs font-medium whitespace-nowrap text-amber-800 ring-1 ring-amber-200 ring-inset">Simulated</span>
                        </div>
                        <dl class="mt-4 grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2 lg:grid-cols-3">
                            @foreach (['Platform' => 'WooCommerce', 'Mode' => 'Simulation', 'Connection' => 'Simulated', 'Products' => 'Sample catalog', 'Linked products' => number_format($store->links_count), 'Last sync' => $store->last_sync_at?->format('M j, H:i') ?? '—'] as $label => $value)
                                <div class="flex justify-between gap-3"><dt class="text-gray-500">{{ $label }}</dt><dd>{{ $value }}</dd></div>
                            @endforeach
                        </dl>
                        <div class="mt-4 flex flex-wrap items-center gap-2 border-t border-gray-100 pt-4">
                            <a href="{{ route('integrations.demo.show', $store) }}" class="btn btn-primary">Open Demo</a>
                            <a href="{{ route('integrations.products', $store) }}" class="btn btn-secondary">Linked Products</a>
                        </div>
                    </section>
                    @continue
                @endif

                @php($language = $store->capabilities['language'] ?? null)
                @php($seo = $store->capabilities['seo'] ?? null)
                <section class="card p-5 sm:p-6">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <div class="min-w-0">
                            <h2 class="truncate text-base font-semibold">{{ $store->name }}</h2>
                            <p class="truncate text-sm text-gray-500">{{ $store->url }}</p>
                        </div>
                        <span @class([
                            'self-start rounded-md px-2 py-0.5 text-xs font-medium whitespace-nowrap ring-1 ring-inset',
                            'bg-emerald-50 text-emerald-700 ring-emerald-200' => $store->status === App\Models\WooCommerceStore::CONNECTED,
                            'bg-red-50 text-red-700 ring-red-200' => $store->status === App\Models\WooCommerceStore::FAILING,
                            'bg-gray-100 text-gray-600 ring-gray-200' => in_array($store->status, [App\Models\WooCommerceStore::UNTESTED, App\Models\WooCommerceStore::DISCONNECTED], true),
                        ])>{{ $store->statusLabel() }}</span>
                    </div>

                    @if ($store->lastErrorMessage())
                        <p class="mt-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 ring-1 ring-red-200">{{ $store->lastErrorMessage() }}</p>
                    @endif

                    <dl class="mt-4 grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2 lg:grid-cols-3">
                        <div class="flex justify-between gap-3"><dt class="text-gray-500">Platform</dt><dd>WooCommerce</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-gray-500">Linked products</dt><dd class="tabular-nums">{{ number_format($store->links_count) }}</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-gray-500">Last check</dt><dd>{{ $store->last_checked_at?->format('M j, H:i') ?? '—' }}</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-gray-500">Last import</dt><dd>{{ $store->last_import_at?->format('M j, H:i') ?? '—' }}</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-gray-500">Last sync</dt><dd>{{ $store->last_sync_at?->format('M j, H:i') ?? '—' }}</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-gray-500">Languages</dt><dd class="text-right">{{ ['none' => 'Single language', 'wpml' => 'WPML'][$language['provider'] ?? ''] ?? ($language ? ucfirst($language['provider']) : '—') }}</dd></div>
                    </dl>

                    @if ($language && ! $language['supported'])
                        <p class="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800 ring-1 ring-amber-200">{{ $language['reason'] }}</p>
                    @endif

                    @if ($seo)
                        <p class="mt-3 text-xs text-gray-500">SEO Sync Not Supported. {{ $seo['reason'] }} SEO metadata stays in this app.</p>
                    @endif

                    <div class="mt-4 flex flex-wrap items-center gap-2 border-t border-gray-100 pt-4">
                        @unless ($store->isDisconnected())
                            <a href="{{ route('integrations.imports.create', $store) }}" class="btn btn-primary"><x-icon name="download" class="size-4" /> Import Products</a>
                        @endunless
                        <a href="{{ route('integrations.products', $store) }}" class="btn btn-secondary">Linked Products</a>
                        @unless ($store->isDisconnected())
                            <form method="POST" action="{{ route('integrations.test', $store) }}" data-single-submit>
                                @csrf
                                <button type="submit" class="btn btn-secondary">Test Connection</button>
                            </form>
                        @endunless
                        <a href="{{ route('integrations.edit', $store) }}" class="btn btn-secondary">{{ $store->isDisconnected() ? 'Reconnect' : 'Edit' }}</a>

                        @unless ($store->isDisconnected())
                            <details class="ml-auto">
                                <summary class="btn btn-danger-soft">Disconnect</summary>
                                <form method="POST" action="{{ route('integrations.disconnect', $store) }}" data-single-submit class="mt-3 max-w-sm space-y-3 rounded-lg bg-red-50 p-3">
                                    @csrf
                                    <p class="text-sm text-red-800">This removes the saved credentials and stops future syncs. Local products, content and history stay, and nothing in your store is deleted.</p>
                                    <label class="flex items-start gap-2 text-sm text-red-800">
                                        <input type="checkbox" name="confirm" value="1" required class="mt-0.5 size-4 rounded accent-red-600">
                                        Disconnect {{ $store->name }}
                                    </label>
                                    <button type="submit" class="btn btn-danger">Disconnect store</button>
                                </form>
                            </details>
                        @endunless
                    </div>
                </section>
            @endforeach
        </div>
    @endif
</x-layouts.app>
