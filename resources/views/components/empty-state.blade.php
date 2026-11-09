@props(['icon', 'title', 'description'])

<div {{ $attributes->merge(['class' => 'flex flex-col items-center px-6 py-14 text-center']) }}>
    <span class="grid size-12 place-items-center rounded-full bg-indigo-50 text-indigo-600">
        <x-icon :name="$icon" class="size-6" />
    </span>
    <h2 class="mt-4 text-base font-semibold">{{ $title }}</h2>
    <p class="mt-1 max-w-md text-sm text-gray-500">{{ $description }}</p>

    @if ($slot->isNotEmpty())
        <div class="mt-6">{{ $slot }}</div>
    @endif
</div>
