@php($isDemo = $mode === App\Enums\BillingMode::Demo)

<x-layouts.app title="Change Plan">
    <a href="{{ route('pricing') }}" class="inline-flex items-center gap-1.5 text-sm font-medium text-gray-500 hover:text-gray-900">
        <x-icon name="arrow-left" class="size-4" /> Pricing
    </a>

    <x-page-header :title="'Change to '.$target->name" :description="'From your '.$current->name.' plan.'">
        <x-slot:actions>
            <x-billing-mode :mode="$mode" />
        </x-slot:actions>
    </x-page-header>

    @if ($isDemo)
        <p role="note" class="rounded-lg bg-amber-50 px-4 py-3 text-sm font-medium text-amber-900 ring-1 ring-amber-200">Simulation: no payment will be taken and no card is needed.</p>
    @endif

    @if ($unavailableReason)
        <p class="rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-800 ring-1 ring-amber-200">{{ $unavailableReason }}</p>
    @endif

    <form method="POST" action="{{ route('billing.plans.change', $target->key) }}" data-single-submit class="card max-w-2xl space-y-4 p-5 sm:p-6">
        @csrf

        @if ($errors->any())
            <p role="alert" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 ring-1 ring-red-200">{{ $errors->first() }}</p>
        @endif

        <dl class="space-y-2 text-sm">
            <div class="flex justify-between gap-4"><dt class="text-gray-500">{{ $isDemo ? 'Simulated price' : 'Price' }}</dt><dd class="font-medium">{{ $target->displayPrice() }} / month</dd></div>
            <div class="flex justify-between gap-4"><dt class="text-gray-500">AI generations</dt><dd>{{ number_format($target->limit('ai_generations')) }} / month</dd></div>
            <div class="flex justify-between gap-4"><dt class="text-gray-500">Products</dt><dd>{{ number_format($target->limit('products')) }}</dd></div>
        </dl>

        @if ($target->rank < $current->rank)
            <p class="text-sm text-gray-600">Downgrading never deletes anything. Existing products, content, history and store links stay, but new actions must fit the lower plan.</p>
        @endif

        <label class="flex items-start gap-2 text-sm">
            <input type="checkbox" name="confirm" value="1" required class="mt-0.5 size-4 rounded accent-indigo-600">
            {{ $isDemo ? 'Simulate this plan change.' : 'I want to change my plan.' }}
        </label>

        <div class="flex justify-end gap-3">
            <a href="{{ route('billing.index') }}" class="btn btn-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary" @disabled($unavailableReason)>{{ $isDemo ? 'Confirm simulation' : 'Continue' }}</button>
        </div>
    </form>
</x-layouts.app>
