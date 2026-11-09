@inject('quota', 'App\Services\AI\GenerationQuota')

@php
    use App\Services\AI\Quality\SeoAnalyzer;

    $languages = App\Enums\ContentLanguage::cases();
    $existingLanguages = $product->contents->map(fn ($content) => $content->language->value)->values();
    $defaultLanguage = collect($languages)->first(fn ($language) => ! $existingLanguages->contains($language->value)) ?? $languages[0];

    // Prefilled from the most recent generation, so settings carry over between products.
    $preferences = App\Services\AI\GenerationPreferences::fromHistory(
        auth()->user()->aiGenerations()->whereNotNull('preferences')->latest('id')->value('preferences')
    );

    $range = fn (array $target) => $target[0].'–'.$target[1].' characters';

    $fields = [
        'title' => ['label' => 'Title', 'max' => 255, 'target' => $range(SeoAnalyzer::TITLE_LENGTH)],
        'short_description' => ['label' => 'Short description', 'max' => 1000, 'rows' => 2, 'target' => '30–320 characters'],
        'long_description' => ['label' => 'Long description', 'max' => 20000, 'rows' => 8, 'target' => 'at least '.SeoAnalyzer::LONG_DESCRIPTION_WORDS.' words', 'count' => 'words'],
        'meta_title' => ['label' => 'Meta title', 'max' => 255, 'target' => $range(SeoAnalyzer::META_TITLE_LENGTH)],
        'meta_description' => ['label' => 'Meta description', 'max' => 500, 'rows' => 2, 'target' => $range(SeoAnalyzer::META_DESCRIPTION_LENGTH)],
        'slug' => ['label' => 'URL slug', 'max' => 255, 'target' => 'up to '.SeoAnalyzer::SLUG_MAX_LENGTH.' characters'],
        'keywords' => ['label' => 'Keywords', 'max' => null, 'hint' => 'Separate keywords with commas.'],
    ];

    $footerClasses = 'sticky bottom-0 z-10 -mx-5 flex flex-wrap items-center justify-end gap-2 rounded-b-xl border-t border-gray-200 bg-white px-5 py-3 sm:-mx-6 sm:px-6';
@endphp

<div data-ai-panel
    data-generate-url="{{ route('ai.generate', $product) }}"
    data-csrf="{{ csrf_token() }}"
    data-existing-languages="{{ $existingLanguages->toJson() }}">

    <div data-ai-setup class="space-y-5">
        <div>
            <label for="ai-language" class="mb-1.5 block text-sm font-medium text-gray-700">Language</label>
            <select id="ai-language" data-ai-language class="field">
                @foreach ($languages as $language)
                    <option value="{{ $language->value }}" @selected($language === $defaultLanguage)>{{ $language->label() }}</option>
                @endforeach
            </select>
            <p data-ai-existing-warning hidden class="mt-2 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800 ring-1 ring-amber-200">
                Content already exists for this language. Generating won't change it, and replacing it will need your confirmation.
            </p>
        </div>

        @include('ai.partials.advanced-options')

        <div class="rounded-lg bg-gray-50 p-4">
            <h3 class="text-sm font-semibold">Information sent to Gemini</h3>
            <dl class="mt-3 space-y-2 text-sm">
                @foreach (['Name' => $product->original_name, 'Category' => $product->category, 'Material' => $product->material, 'Details' => $product->details] as $label => $value)
                    <div class="flex gap-4">
                        <dt class="w-20 shrink-0 text-gray-500">{{ $label }}</dt>
                        <dd class="min-w-0 break-words whitespace-pre-line">{{ $value ?? '—' }}</dd>
                    </div>
                @endforeach
            </dl>
        </div>

        <div data-ai-error hidden role="alert" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 ring-1 ring-red-200"></div>

        <div class="{{ $footerClasses }}">
            <p class="mr-auto text-xs text-gray-500"><span data-ai-remaining>{{ $quota->remainingFor(auth()->user()) }}</span> generations left today</p>
            <button type="button" data-ai-generate class="btn btn-primary">
                <x-icon name="sparkles" class="size-4" data-ai-idle-icon />
                <x-icon name="spinner" class="size-4 animate-spin" data-ai-busy-icon hidden />
                <span data-ai-generate-label>Generate with AI</span>
            </button>
        </div>
    </div>

    <form data-ai-preview hidden class="space-y-5">
        <div>
            <h3 class="text-base font-semibold">Review <span data-ai-preview-language></span> content</h3>
            <p class="mt-1 text-sm text-gray-500">Nothing is saved until you click Save content. <span data-ai-usage></span></p>
            <p data-ai-preferences class="mt-1 text-xs text-gray-500"></p>
        </div>

        <section aria-labelledby="ai-seo-heading" class="rounded-lg bg-gray-50 p-4">
            <div class="flex items-center justify-between gap-4">
                <h4 id="ai-seo-heading" class="text-sm font-semibold">SEO Quality</h4>
                <p class="text-sm font-semibold tabular-nums"><span data-ai-seo-score>0</span>/100</p>
            </div>
            <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-gray-200" aria-hidden="true">
                <div data-ai-seo-bar class="h-full rounded-full bg-indigo-600 transition-[width]" style="width: 0%"></div>
            </div>

            <ul data-ai-seo-checks class="mt-3 grid gap-x-6 gap-y-1.5 text-sm sm:grid-cols-2"></ul>

            <div data-ai-seo-warnings-wrap hidden class="mt-3">
                <h5 class="text-xs font-medium text-gray-500">Warnings</h5>
                <ul data-ai-seo-warnings class="mt-1 list-disc space-y-1 pl-4 text-sm text-amber-800"></ul>
            </div>

            <div data-ai-seo-suggestions-wrap hidden class="mt-3">
                <h5 class="text-xs font-medium text-gray-500">Suggestions</h5>
                <ul data-ai-seo-suggestions class="mt-1 list-disc space-y-1 pl-4 text-sm text-gray-700"></ul>
            </div>

            <details class="mt-3 text-xs text-gray-500">
                <summary class="font-medium">How this score works</summary>
                <ul class="mt-2 list-disc space-y-1 pl-4">
                    @foreach (SeoAnalyzer::rules() as $rule)
                        <li>{{ $rule }}</li>
                    @endforeach
                </ul>
            </details>
            <p class="mt-2 text-xs text-gray-500">A checklist of common recommendations. It doesn't predict search rankings.</p>
        </section>

        <section data-ai-quality-wrap hidden aria-labelledby="ai-quality-heading" class="rounded-lg bg-amber-50 p-4 ring-1 ring-amber-200">
            <h4 id="ai-quality-heading" class="text-sm font-semibold text-amber-900">Please review</h4>
            <p class="mt-0.5 text-xs text-amber-800">Automatic checks against your product information. These may be fine, especially for translated terms.</p>
            <ul data-ai-quality class="mt-2 list-disc space-y-1 pl-4 text-sm text-amber-900"></ul>
        </section>

        <details data-ai-compare-wrap hidden open class="rounded-lg ring-1 ring-gray-200">
            <summary class="px-4 py-3 text-sm font-medium text-gray-700">
                Compare with saved content
                <span data-ai-compare-count class="ml-1 font-normal text-gray-500"></span>
            </summary>
            <div data-ai-compare class="divide-y divide-gray-100 border-t border-gray-200 px-4"></div>
        </details>

        @foreach ($fields as $name => $field)
            <div>
                @isset($field['rows'])
                    <x-form.textarea :name="$name" :label="$field['label']" :rows="$field['rows']" :maxlength="$field['max']" required data-ai-field="{{ $name }}" data-ai-autosize class="resize-none overflow-hidden" />
                @else
                    <x-form.input :name="$name" :label="$field['label']" :maxlength="$field['max']" :hint="$field['hint'] ?? null" required data-ai-field="{{ $name }}" />
                @endisset
                <p data-ai-field-error="{{ $name }}" hidden class="mt-1.5 text-sm text-red-600"></p>
                <div class="mt-1 flex flex-wrap items-start justify-between gap-x-3">
                    <p data-ai-feedback="{{ $name }}" class="min-w-0 text-xs text-amber-700"></p>
                    @isset($field['target'])
                        <p data-ai-counter="{{ $name }}" data-ai-counter-unit="{{ $field['count'] ?? 'characters' }}" data-ai-counter-target="{{ $field['target'] }}" class="ml-auto text-right text-xs text-gray-500 tabular-nums"></p>
                    @endisset
                </div>
            </div>
        @endforeach

        <label data-ai-confirm-row hidden class="flex items-start gap-2 rounded-lg bg-amber-50 px-3 py-2.5 text-sm text-amber-800 ring-1 ring-amber-200">
            <input type="checkbox" data-ai-confirm class="mt-0.5 size-4 rounded border-gray-300 accent-indigo-600">
            <span>Replace the existing <span data-ai-confirm-language></span> content. This can't be undone.</span>
        </label>

        <div data-ai-preview-error hidden role="alert" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 ring-1 ring-red-200"></div>

        <p class="text-xs text-gray-500">Regenerating sends a new AI request, uses one generation and replaces this preview.</p>

        <div class="{{ $footerClasses }}">
            <button type="button" data-ai-cancel class="btn btn-secondary">Cancel</button>
            <button type="button" data-ai-regenerate class="btn btn-secondary">
                <x-icon name="spinner" class="size-4 animate-spin" data-ai-busy-icon hidden />
                Regenerate
            </button>
            <button type="submit" data-ai-save class="btn btn-primary">Save content</button>
        </div>
    </form>
</div>
