<x-layouts.app title="Import & Export">
    <x-page-header title="Import &amp; Export" description="Your recent catalog imports and exports. Files are removed after {{ config('catalog.retention_days') }} days.">
        <x-slot:actions>
            <a href="{{ route('catalog.imports.create') }}" class="btn btn-secondary"><x-icon name="upload" class="size-4" /> Import Products</a>
            <a href="{{ route('catalog.exports.create') }}" class="btn btn-secondary"><x-icon name="download" class="size-4" /> Export Products</a>
        </x-slot:actions>
    </x-page-header>

    @if ($errors->any())
        <div role="alert" class="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-red-200">{{ $errors->first() }}</div>
    @endif

    <div class="card" @if ($transfers->contains(fn ($transfer) => $transfer->status->isRunning())) data-auto-refresh="5000" @endif>
        @if ($transfers->isEmpty())
            <x-empty-state icon="document" title="No imports or exports yet" description="Your catalog imports and exports will be listed here." />
        @else
            <ul class="divide-y divide-gray-100">
                @foreach ($transfers as $transfer)
                    <li class="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between sm:px-5">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-medium">
                                {{ ucfirst($transfer->operation) }} · {{ $transfer->file_type === App\Services\WooCommerce\WooCommerceProductImporter::FILE_TYPE ? 'WooCommerce' : strtoupper($transfer->file_type) }}
                                @if ($transfer->original_name)
                                    <span class="font-normal text-gray-500">{{ $transfer->original_name }}</span>
                                @endif
                            </p>
                            <p class="mt-0.5 text-xs text-gray-500">
                                {{ $transfer->created_at->format('M j, Y, H:i') }}
                                @if ($transfer->completed_at)
                                    · finished {{ $transfer->completed_at->format('H:i') }}
                                @endif
                                · {{ number_format($transfer->total_records) }} records, {{ number_format($transfer->successful_records) }} successful, {{ number_format($transfer->failed_records) }} failed
                            </p>
                            @if ($transfer->error_summary)
                                <p class="mt-1 text-sm text-red-700">{{ $transfer->error_summary }}</p>
                            @endif
                        </div>

                        <div class="flex shrink-0 items-center gap-3">
                            <x-transfer-status :status="$transfer->status" />
                            @if ($transfer->file_type === App\Services\WooCommerce\WooCommerceProductImporter::FILE_TYPE)
                                <a href="{{ route('integrations.imports.show', $transfer) }}" class="btn btn-secondary">View</a>
                            @elseif ($transfer->isImport())
                                <a href="{{ route('catalog.imports.show', $transfer) }}" class="btn btn-secondary">View</a>
                            @elseif ($transfer->stored_path)
                                <a href="{{ route('catalog.exports.download', $transfer) }}" class="btn btn-secondary"><x-icon name="download" class="size-4" /> Download</a>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>

            @if ($transfers->hasPages())
                <div class="border-t border-gray-200 px-4 py-3">{{ $transfers->links() }}</div>
            @endif
        @endif
    </div>
</x-layouts.app>
