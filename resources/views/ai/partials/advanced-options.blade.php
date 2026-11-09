@php
    $selects = [
        'tone' => ['label' => 'Tone of voice', 'cases' => App\Enums\ContentTone::cases(), 'selected' => $preferences->tone],
        'audience' => ['label' => 'Target audience', 'cases' => App\Enums\TargetAudience::cases(), 'selected' => $preferences->audience],
        'length' => ['label' => 'Content length', 'cases' => App\Enums\ContentLength::cases(), 'selected' => $preferences->length],
        'style' => ['label' => 'Writing style', 'cases' => App\Enums\WritingStyle::cases(), 'selected' => $preferences->style],
    ];
@endphp

<details data-ai-advanced class="group rounded-lg ring-1 ring-gray-200">
    <summary class="flex items-center justify-between px-4 py-3 text-sm font-medium text-gray-700">
        Advanced Options
        <span class="text-xs font-normal text-gray-500 group-open:hidden">Tone, audience, length and style</span>
    </summary>

    <div class="grid gap-4 border-t border-gray-200 p-4 sm:grid-cols-2">
        @foreach ($selects as $name => $select)
            <div>
                <label for="ai-{{ $name }}" class="mb-1.5 block text-sm font-medium text-gray-700">{{ $select['label'] }}</label>
                <select id="ai-{{ $name }}" name="{{ $name }}" data-ai-preference="{{ $name }}" class="field">
                    @foreach ($select['cases'] as $case)
                        <option value="{{ $case->value }}" @selected($case === $select['selected'])>{{ $case->label() }}</option>
                    @endforeach
                </select>
            </div>
        @endforeach

        <div data-ai-custom-audience-row @if ($preferences->audience !== App\Enums\TargetAudience::Custom) hidden @endif class="sm:col-span-2">
            <label for="ai-custom-audience" class="mb-1.5 block text-sm font-medium text-gray-700">Describe your audience</label>
            <input id="ai-custom-audience" type="text" name="custom_audience" data-ai-preference="custom_audience" maxlength="80" value="{{ $preferences->customAudience }}" class="field">
        </div>

        <div class="sm:col-span-2">
            <label for="ai-custom-instructions" class="mb-1.5 block text-sm font-medium text-gray-700">Additional instructions for AI</label>
            <textarea id="ai-custom-instructions" name="custom_instructions" data-ai-preference="custom_instructions" rows="3" maxlength="1000" placeholder="For example: Avoid overly promotional language." class="field"></textarea>
            <p class="mt-1.5 text-xs text-gray-500">Used for wording and emphasis only. Product facts and accuracy rules always come first.</p>
        </div>
    </div>
</details>
