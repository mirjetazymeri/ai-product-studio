@props(['label', 'value', 'icon'])

<div class="card flex items-center justify-between p-5">
    <div>
        <p class="text-sm text-gray-500">{{ $label }}</p>
        <p class="mt-1 text-2xl font-semibold tracking-tight tabular-nums">{{ number_format($value) }}</p>
    </div>
    <span class="grid size-10 place-items-center rounded-lg bg-gray-100 text-gray-500">
        <x-icon :name="$icon" />
    </span>
</div>
