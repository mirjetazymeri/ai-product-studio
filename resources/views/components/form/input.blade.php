@props(['name', 'label', 'type' => 'text', 'value' => null, 'hint' => null, 'bag' => 'default', 'errorKey' => null])

@php
    $id = $attributes->get('id', str_replace(['[', ']'], ['-', ''], $name));
    $key = str_replace(['[', ']'], ['.', ''], $name);
    $message = $errors->getBag($bag)->first($errorKey ?? $key);
@endphp

<div>
    <label for="{{ $id }}" class="mb-1.5 block text-sm font-medium text-gray-700">{{ $label }}</label>
    <input id="{{ $id }}" name="{{ $name }}" type="{{ $type }}"
        @if ($value !== null) value="{{ $value }}" @endif
        @if ($message) aria-invalid="true" aria-describedby="{{ $id }}-error" @endif
        {{ $attributes->except('id')->class(['field', 'field-invalid' => $message]) }}>

    @if ($message)
        <p id="{{ $id }}-error" class="mt-1.5 text-sm text-red-600">{{ $message }}</p>
    @elseif ($hint)
        <p class="mt-1.5 text-xs text-gray-500">{{ $hint }}</p>
    @endif
</div>
