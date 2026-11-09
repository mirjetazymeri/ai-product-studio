<x-layouts.app title="Import Results">
    <x-page-header title="Import results" :description="$transfer->original_name">
        <x-slot:actions>
            <x-transfer-status :status="$transfer->status" />
        </x-slot:actions>
    </x-page-header>

    @if ($transfer->status->isRunning())
        @php($percent = $pending > 0 ? min(100, (int) round($processed / $pending * 100)) : 0)
        <section class="card p-5" data-auto-refresh="3000">
            <div class="flex justify-between text-sm">
                <span class="font-medium">{{ $transfer->status->label() }}</span>
                <span class="tabular-nums text-gray-500">{{ number_format($processed) }} of {{ number_format($pending) }} rows</span>
            </div>
            <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-gray-200" aria-hidden="true">
                <div class="h-full rounded-full bg-indigo-600 transition-[width]" style="width: {{ $percent }}%"></div>
            </div>
            @if ($transfer->status === App\Enums\CatalogTransferStatus::Queued)
                <p class="mt-3 text-sm text-gray-500">Large imports run in the background. If this stays queued, make sure the queue worker is running: <code class="font-mono text-xs">php artisan queue:work</code></p>
            @endif
        </section>
    @endif

    @if ($transfer->status === App\Enums\CatalogTransferStatus::Failed)
        <section class="card flex flex-col gap-4 p-5 sm:flex-row sm:items-center sm:justify-between">
            <p class="text-sm text-red-700">{{ $transfer->error_summary }}</p>
            <form method="POST" action="{{ route('catalog.imports.retry', $transfer) }}" data-single-submit>
                @csrf
                <button type="submit" class="btn btn-primary">Continue import</button>
            </form>
        </section>
    @endif

    @if ($transfer->status->isFinished())
        <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4" aria-label="Results">
            <x-stat-card label="Products created" :value="$transfer->summary['created'] ?? 0" icon="plus" />
            <x-stat-card label="Products updated" :value="$transfer->summary['updated'] ?? 0" icon="pencil" />
            <x-stat-card label="Products skipped" :value="$transfer->summary['skipped'] ?? 0" icon="eye" />
            <x-stat-card label="Rows with errors" :value="$transfer->failed_records" icon="close" />
        </section>

        <div class="flex flex-wrap gap-3">
            <a href="{{ route('products.index') }}" class="btn btn-primary">View products</a>
            <a href="{{ route('catalog.history') }}" class="btn btn-secondary">Import &amp; export history</a>
        </div>
    @endif

    @if ($problemRows->isNotEmpty())
        <section class="card">
            <div class="flex items-center justify-between gap-4 border-b border-gray-200 px-5 py-4">
                <h2 class="text-sm font-semibold">Rows with errors</h2>
                <a href="{{ route('catalog.imports.errors', $transfer) }}" class="btn btn-secondary"><x-icon name="download" class="size-4" /> Download errors</a>
            </div>
            <ul class="divide-y divide-gray-100">
                @foreach ($problemRows as $row)
                    <li class="px-5 py-3 text-sm">
                        <span class="font-medium">Row {{ $row->first_row }}</span>
                        <span class="text-gray-500">{{ $row->payload['product']['original_name'] ?? '' }}</span>
                        <p class="mt-0.5 text-red-700">{{ implode(' ', $row->errors ?? [$row->result_message]) }}</p>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
</x-layouts.app>
