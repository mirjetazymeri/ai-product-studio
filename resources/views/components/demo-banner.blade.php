<div role="note" {{ $attributes->merge(['class' => 'flex flex-col gap-0.5 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-900 ring-1 ring-amber-200']) }}>
    <p class="font-semibold">Demo Mode — No Real WooCommerce Connection</p>
    @if ($slot->isNotEmpty())
        <p class="text-amber-800">{{ $slot }}</p>
    @endif
</div>
