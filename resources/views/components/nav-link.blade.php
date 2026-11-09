@props(['href', 'icon', 'active' => false])

<a href="{{ $href }}" @if ($active) aria-current="page" @endif @class([
    'flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors',
    'bg-indigo-50 text-indigo-700' => $active,
    'text-gray-600 hover:bg-gray-100 hover:text-gray-900' => ! $active,
])>
    <x-icon :name="$icon" @class(['text-indigo-600' => $active, 'text-gray-400' => ! $active]) />
    {{ $slot }}
</a>
