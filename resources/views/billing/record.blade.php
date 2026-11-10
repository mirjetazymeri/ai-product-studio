<x-layouts.app title="Simulated Record">
    <a href="{{ route('billing.index') }}" class="inline-flex items-center gap-1.5 text-sm font-medium text-gray-500 hover:text-gray-900">
        <x-icon name="arrow-left" class="size-4" /> Billing &amp; Subscription
    </a>

    <section class="card relative max-w-2xl overflow-hidden p-5 sm:p-6">
        <p aria-hidden="true" class="pointer-events-none absolute inset-0 grid -rotate-12 place-items-center text-6xl font-bold tracking-widest text-gray-100 select-none">DEMO</p>

        <div class="relative">
            <h1 class="text-base font-semibold">Simulated billing record</h1>
            <p class="mt-1 text-sm font-medium text-amber-800">Simulation. This isn't an invoice or a receipt, and no money moved.</p>

            <dl class="mt-5 space-y-3 text-sm">
                <div class="flex justify-between gap-4"><dt class="text-gray-500">Reference</dt><dd class="min-w-0 text-right font-mono text-xs break-all">{{ $record->reference }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-gray-500">Date</dt><dd>{{ $record->date->format('M j, Y, H:i') }} UTC</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-gray-500">Description</dt><dd class="text-right">{{ $record->description }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-gray-500">Illustrative amount</dt><dd>{{ $record->displayAmount() }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-gray-500">Status</dt><dd>{{ ucfirst($record->status) }}</dd></div>
            </dl>
        </div>
    </section>
</x-layouts.app>
