@props(['label', 'used', 'limit', 'percent', 'hint' => null])

<div>
    <div class="flex items-baseline justify-between gap-3 text-sm">
        <span class="font-medium">{{ $label }}</span>
        <span class="text-gray-500 tabular-nums">{{ number_format($used) }} / {{ number_format($limit) }}</span>
    </div>
    <div class="mt-1.5 h-2 overflow-hidden rounded-full bg-gray-200" role="progressbar" aria-label="{{ $label }}" aria-valuemin="0" aria-valuemax="{{ $limit }}" aria-valuenow="{{ min($used, $limit) }}">
        <div @class(['h-full rounded-full', 'bg-red-500' => $percent >= 100, 'bg-indigo-600' => $percent < 100]) style="width: {{ $percent }}%"></div>
    </div>
    @if ($hint)
        <p class="mt-1 text-xs text-gray-500">{{ $hint }}</p>
    @endif
</div>
