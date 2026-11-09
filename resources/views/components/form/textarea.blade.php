@props(['name', 'label', 'value' => null, 'hint' => null, 'rows' => 4])

@php
    $id = str_replace(['[', ']'], ['-', ''], $name);
    $key = str_replace(['[', ']'], ['.', ''], $name);
    $message = $errors->first($key);
@endphp

<div>
    <label for="{{ $id }}" class="mb-1.5 block text-sm font-medium text-gray-700">{{ $label }}</label>
    <textarea id="{{ $id }}" name="{{ $name }}" rows="{{ $rows }}"
        @if ($message) aria-invalid="true" aria-describedby="{{ $id }}-error" @endif
        {{ $attributes->class(['field', 'field-invalid' => $message]) }}>{{ $value }}</textarea>

    @if ($message)
        <p id="{{ $id }}-error" class="mt-1.5 text-sm text-red-600">{{ $message }}</p>
    @elseif ($hint)
        <p class="mt-1.5 text-xs text-gray-500">{{ $hint }}</p>
    @endif
</div>
