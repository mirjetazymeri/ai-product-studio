@php
    use App\Models\WooCommerceSyncItem as Item;
    use App\Models\WooCommerceSyncRun as Run;

    $isReady = $run->status === Run::READY;
    $badge = fn (string $status) => match ($status) {
        Item::COMPLETED => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
        Item::FAILED, Item::CONFLICT => 'bg-red-50 text-red-700 ring-red-200',
        Item::PARTIALLY_COMPLETED => 'bg-amber-50 text-amber-700 ring-amber-200',
        default => 'bg-gray-100 text-gray-600 ring-gray-200',
    };
@endphp

<x-layouts.app :title="$run->isRollback() ? 'Rollback Preview' : 'Sync Preview'">
    <x-page-header :title="$isReady ? ($run->isRollback() ? 'Rollback preview' : 'Review changes') : ($run->isRollback() ? 'Rollback' : 'Synchronization')" :description="$run->store->name.' · '.$run->language->label()">
        <x-slot:actions>
            <span class="rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset {{ $badge($run->status) }}">{{ $run->statusLabel() }}</span>
            <a href="{{ route('integrations.sync.history') }}" class="btn btn-secondary">Sync History</a>
        </x-slot:actions>
    </x-page-header>

    @if ($run->store->isDemo())
        <x-demo-banner>This is a simulated synchronization. Changes are saved to the demo catalog only, and no real WooCommerce store is involved.</x-demo-banner>
    @endif

    @if ($errors->any())
        <div role="alert" class="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-red-200">{{ $errors->first() }}</div>
    @endif

    @if ($run->isActive())
        <section class="card p-5" data-auto-refresh="3000">
            <p class="text-sm font-medium">{{ $run->statusLabel() }}</p>
            <p class="mt-1 text-sm text-gray-500">Larger runs continue in the background. If nothing changes, make sure the queue worker is running: <code class="font-mono text-xs">php artisan queue:work</code></p>
        </section>
    @endif

    @if ($run->error_summary)
        <p class="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-red-200">{{ $run->error_summary }}</p>
    @endif

    @if ($isReady)
        <form id="sync-confirm" method="POST" action="{{ route('integrations.sync.confirm', $run) }}" data-single-submit>
            @csrf
        </form>
    @endif

    <div class="space-y-4">
        @foreach ($items as $item)
            <section class="card">
                <div class="flex flex-wrap items-start justify-between gap-3 border-b border-gray-200 px-5 py-4">
                    <div class="min-w-0">
                        <h2 class="truncate text-sm font-semibold">{{ $item->product_name }}</h2>
                        <p class="mt-0.5 text-xs text-gray-500">
                            @if ($item->product_id) Local #{{ $item->product_id }} · @endif
                            @if ($item->remote_id) WooCommerce #{{ $item->remote_id }} · @endif
                            {{ $item->language->label() }}
                            @if ($item->attempts > 0) · {{ $item->attempts }} {{ Str::plural('attempt', $item->attempts) }} @endif
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        @if ($run->store->isDemo() && $item->remote_id)
                            <a href="{{ route('integrations.demo.product', [$run->store, $item->remote_id]) }}" class="text-xs font-medium text-indigo-600 hover:text-indigo-700">View simulated product</a>
                        @endif
                        @if ($run->store->isDemo() && $isReady && $item->status === Item::READY && ! $run->isRollback())
                            <form method="POST" action="{{ route('integrations.demo.simulate-change', $item) }}" data-single-submit>
                                @csrf
                                <button type="submit" class="btn btn-secondary">Simulate Remote Change</button>
                            </form>
                        @endif
                        <span class="rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset {{ $badge($item->status) }}">{{ $item->statusLabel() }}</span>
                    </div>
                </div>

                @if ($item->message)
                    <p class="border-b border-gray-100 px-5 py-3 text-sm {{ in_array($item->status, [Item::FAILED, Item::CONFLICT], true) ? 'text-red-700' : 'text-gray-600' }}">{{ $item->message }}</p>
                @endif

                <ul class="divide-y divide-gray-100">
                    @foreach ($item->fields ?? [] as $name => $field)
                        @continue($field['supported'] && ! $field['changed'] && ! $field['selected'])
                        <li class="px-5 py-4">
                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <h3 class="text-sm font-medium">{{ $field['label'] }}</h3>

                                @if (! $field['supported'] && empty($field['blocked']))
                                    <span class="rounded-md bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600">SEO Sync Not Supported</span>
                                @elseif (! empty($field['blocked']))
                                    <span class="rounded-md bg-red-50 px-2 py-0.5 text-xs font-medium text-red-700">Changed since sync</span>
                                @elseif ($isReady && $item->status === Item::READY)
                                    <label class="flex items-center gap-2 text-sm">
                                        <input type="checkbox" form="sync-confirm" name="selected[{{ $item->id }}][]" value="{{ $name }}" @checked($name !== 'slug') class="size-4 rounded accent-indigo-600">
                                        Update this field
                                    </label>
                                @else
                                    <span class="text-xs text-gray-500">Selected for update: {{ $field['selected'] ? 'Yes' : 'No' }}</span>
                                @endif
                            </div>

                            <div class="mt-2 grid gap-3 sm:grid-cols-2">
                                <div class="min-w-0 rounded-md bg-gray-50 p-2.5">
                                    <p class="text-xs font-medium text-gray-500">
                                        @if (isset($item->conflicts[$name]))
                                            Original value in store
                                        @elseif (isset($item->applied_values[$name]))
                                            Previous value
                                        @else
                                            {{ $field['supported'] || ! empty($field['blocked']) ? 'Current in store' : 'In store' }}
                                        @endif
                                    </p>
                                    <p class="mt-0.5 text-sm break-words whitespace-pre-line">{{ $field['remote_display'] ?: '—' }}</p>
                                </div>
                                <div class="min-w-0 rounded-md bg-indigo-50 p-2.5">
                                    <p class="text-xs font-medium text-gray-500">{{ isset($item->applied_values[$name]) ? 'Updated value' : ($run->isRollback() ? 'Restored value' : 'Proposed') }}</p>
                                    <p class="mt-0.5 text-sm break-words whitespace-pre-line">{{ $field['proposed_display'] ?: '—' }}</p>
                                </div>
                            </div>

                            @isset($item->conflicts[$name])
                                <div class="mt-2 min-w-0 rounded-md bg-red-50 p-2.5">
                                    <p class="text-xs font-medium text-red-700">New value in store</p>
                                    <p class="mt-0.5 text-sm break-words whitespace-pre-line">{{ $item->conflicts[$name] }}</p>
                                </div>
                            @endisset
                        </li>
                    @endforeach
                </ul>

                @if ($protected = $protectedFields->get($item->remote_id))
                    <div class="border-t border-gray-100 px-5 py-3">
                        <p class="text-xs text-gray-500">
                            @if (collect($protected)->every('unchanged'))
                                <span class="font-medium text-emerald-700">Protected fields unchanged:</span>
                            @else
                                <span class="font-medium text-red-700">Protected fields changed:</span>
                            @endif
                            {{ collect($protected)->pluck('label')->implode(', ') }}.
                        </p>
                    </div>
                @endif
            </section>
        @endforeach
    </div>

    @if ($isReady)
        <section class="card space-y-3 p-5 sm:p-6">
            <p class="text-sm text-gray-500">Only the fields ticked above are sent. Prices, stock, images, SKU, status, shipping, tax, attributes and variations are never changed. The store is checked again right before each update, and a product changed in the meantime is skipped as a conflict.</p>
            <label class="flex items-start gap-2 text-sm">
                <input type="checkbox" form="sync-confirm" name="confirm" value="1" required class="mt-0.5 size-4 rounded accent-indigo-600">
                @if ($run->store->isDemo())
                    I've reviewed these changes and want to apply them to the simulated demo store.
                @else
                    I've reviewed these changes and want to write them to {{ $run->store->name }}.
                @endif
            </label>
            <div class="flex justify-end gap-3">
                <a href="{{ route('integrations.products', $run->store) }}" class="btn btn-secondary">Cancel</a>
                <button type="submit" form="sync-confirm" class="btn btn-primary">{{ $run->store->isDemo() ? 'Run simulated synchronization' : 'Confirm synchronization' }}</button>
            </div>
        </section>
    @endif

    @if ($run->isFinished())
        <div class="flex flex-wrap gap-3">
            @if ($items->contains('status', Item::FAILED))
                <form method="POST" action="{{ route('integrations.sync.retry', $run) }}" data-single-submit>
                    @csrf
                    <button type="submit" class="btn btn-primary">Retry failed</button>
                </form>
            @endif

            @if (! $run->isRollback() && $items->contains(fn ($item) => in_array($item->status, [Item::CONFLICT, Item::FAILED], true)))
                <form method="POST" action="{{ route('integrations.sync.refresh', $run) }}" data-single-submit>
                    @csrf
                    <button type="submit" class="btn btn-secondary">Refresh preview</button>
                </form>
            @endif

            @if (! $run->isRollback() && $items->contains(fn ($item) => filled($item->previous_values) && filled($item->applied_values)))
                <form method="POST" action="{{ route('integrations.sync.rollback', $run) }}" data-single-submit>
                    @csrf
                    <button type="submit" class="btn btn-secondary">Preview rollback</button>
                </form>
            @endif

            <a href="{{ route('integrations.products', $run->store) }}" class="btn btn-secondary">Linked products</a>
        </div>
    @endif
</x-layouts.app>
