<x-layouts.app title="Sync History">
    <x-page-header title="Sync History" description="Every preview and synchronization with your stores.">
        <x-slot:actions>
            <a href="{{ route('integrations.index') }}" class="btn btn-secondary">Integrations</a>
        </x-slot:actions>
    </x-page-header>

    @if ($runs->contains(fn ($run) => $run->store->isDemo()))
        <x-demo-banner>Rows marked Simulation changed the demo catalog only. They never reached a real WooCommerce store.</x-demo-banner>
    @endif

    <div class="card">
        @if ($runs->isEmpty())
            <x-empty-state icon="clock" title="No synchronizations yet" description="Select linked products and choose Sync Content to get started." />
        @else
            <ul class="divide-y divide-gray-100">
                @foreach ($runs as $run)
                    <li class="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between sm:px-5">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-medium">{{ $run->store->name }} @if ($run->isRollback()) <span class="font-normal text-gray-500">· Rollback</span> @endif @if ($run->store->isDemo()) <span class="ml-1 rounded-md bg-amber-50 px-1.5 py-0.5 text-xs font-medium text-amber-800 ring-1 ring-amber-200 ring-inset">Simulation</span> @endif</p>
                            <p class="mt-0.5 text-xs text-gray-500">{{ $run->created_at->format('M j, Y, H:i') }} · {{ $run->language->label() }} · {{ $run->items_count }} {{ Str::plural('product', $run->items_count) }}</p>
                            @if ($run->store->isDemo())
                                @foreach ($run->items->filter(fn ($item) => filled($item->applied_values)) as $item)
                                    <p class="mt-0.5 truncate text-xs text-gray-500">{{ $item->product_name }}: {{ collect(array_keys($item->applied_values))->map(fn ($field) => App\Services\WooCommerce\WooCommerceProductMapper::FIELD_LABELS[$field])->implode(', ') }}</p>
                                @endforeach
                            @endif
                            @if ($run->error_summary)
                                <p class="mt-1 text-sm text-red-700">{{ $run->error_summary }}</p>
                            @endif
                        </div>
                        <div class="flex shrink-0 items-center gap-3">
                            <span class="rounded-md bg-gray-100 px-2 py-0.5 text-xs font-medium whitespace-nowrap text-gray-600">{{ $run->statusLabel() }}</span>
                            <a href="{{ route('integrations.sync.show', $run) }}" class="btn btn-secondary">View</a>
                        </div>
                    </li>
                @endforeach
            </ul>

            @if ($runs->hasPages())
                <div class="border-t border-gray-200 px-4 py-3">{{ $runs->links() }}</div>
            @endif
        @endif
    </div>
</x-layouts.app>
