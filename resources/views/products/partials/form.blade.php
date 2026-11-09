@php
    $firstLanguageWithError = collect($languages)->first(fn ($language) => $errors->has("contents.{$language->value}.*"));
    $activeLanguage = $firstLanguageWithError ?? $languages[0];
@endphp

<section class="card p-5 sm:p-6">
    <h2 class="text-base font-semibold">Product information</h2>

    <div class="mt-5 grid gap-4 sm:grid-cols-2">
        <div class="sm:col-span-2">
            <x-form.input name="original_name" label="Product name" :value="old('original_name', $product->original_name)" required maxlength="255" />
        </div>
        <x-form.input name="sku" label="SKU (optional)" :value="old('sku', $product->sku)" maxlength="100" />

        <div>
            <label for="status" class="mb-1.5 block text-sm font-medium text-gray-700">Status</label>
            <select id="status" name="status" class="field">
                @foreach ($statuses as $status)
                    <option value="{{ $status->value }}" @selected(old('status', $product->status->value) === $status->value)>{{ $status->label() }}</option>
                @endforeach
            </select>
            @error('status') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        <x-form.input name="category" label="Category (optional)" :value="old('category', $product->category)" maxlength="120" />
        <x-form.input name="material" label="Material (optional)" :value="old('material', $product->material)" maxlength="120" />

        <div class="sm:col-span-2">
            <x-form.textarea name="details" label="Product details (optional)" :value="old('details', $product->details)" hint="Features, dimensions, care instructions and anything else worth knowing." />
        </div>
    </div>
</section>

<section class="card" data-tabs>
    <div class="px-5 pt-5 sm:px-6 sm:pt-6">
        <h2 class="text-base font-semibold">Content</h2>
        <p class="mt-1 text-sm text-gray-500">Write content per language. A language left empty isn't saved.</p>

        <div role="tablist" aria-label="Content language" class="mt-4 flex gap-1 overflow-x-auto shadow-[inset_0_-1px_0_var(--color-gray-200)]">
            @foreach ($languages as $language)
                @php
                    $hasContent = $product->contentFor($language) !== null;
                    $hasError = $errors->has("contents.{$language->value}.*");
                @endphp
                <button type="button" role="tab" id="tab-{{ $language->value }}" aria-controls="panel-{{ $language->value }}"
                    aria-selected="{{ $language === $activeLanguage ? 'true' : 'false' }}"
                    tabindex="{{ $language === $activeLanguage ? 0 : -1 }}"
                    class="flex items-center gap-2 border-b-2 border-transparent px-3 py-2 text-sm font-medium whitespace-nowrap text-gray-500 transition-colors hover:text-gray-900 aria-selected:border-indigo-600 aria-selected:text-indigo-600">
                    {{ $language->label() }}
                    @if ($hasError)
                        <span class="size-1.5 rounded-full bg-red-500"></span>
                        <span class="sr-only">(has errors)</span>
                    @elseif ($hasContent)
                        <span class="size-1.5 rounded-full bg-emerald-500"></span>
                        <span class="sr-only">(has content)</span>
                    @endif
                </button>
            @endforeach
        </div>
    </div>

    @foreach ($languages as $language)
        @php
            $code = $language->value;
            $content = $product->contentFor($language);
            $keywords = old("contents.{$code}.keywords", $content?->keywords);
        @endphp

        <div role="tabpanel" id="panel-{{ $code }}" aria-labelledby="tab-{{ $code }}" @if ($language !== $activeLanguage) hidden @endif class="p-5 sm:p-6">
            <div class="grid gap-4">
                <x-form.input name="contents[{{ $code }}][title]" label="Title" :value="old('contents.'.$code.'.title', $content?->title)" maxlength="255" />
                <x-form.textarea name="contents[{{ $code }}][short_description]" label="Short description" :value="old('contents.'.$code.'.short_description', $content?->short_description)" rows="2" maxlength="1000" />
                <x-form.textarea name="contents[{{ $code }}][long_description]" label="Long description" :value="old('contents.'.$code.'.long_description', $content?->long_description)" rows="6" maxlength="20000" />

                <div class="border-t border-gray-100 pt-4">
                    <h3 class="text-sm font-semibold">SEO</h3>
                </div>

                <x-form.input name="contents[{{ $code }}][meta_title]" label="Meta title" :value="old('contents.'.$code.'.meta_title', $content?->meta_title)" maxlength="255" hint="Search engines usually show about 60 characters." />
                <x-form.textarea name="contents[{{ $code }}][meta_description]" label="Meta description" :value="old('contents.'.$code.'.meta_description', $content?->meta_description)" rows="2" maxlength="500" hint="Search engines usually show about 160 characters." />
                <x-form.input name="contents[{{ $code }}][slug]" label="URL slug" :value="old('contents.'.$code.'.slug', $content?->slug)" maxlength="255" hint="Leave blank to create it from the title." />
                <x-form.input name="contents[{{ $code }}][keywords]" label="Keywords" :value="is_array($keywords) ? implode(', ', $keywords) : $keywords" :error-key="'contents.'.$code.'.keywords*'" hint="Separate keywords with commas." />
            </div>
        </div>
    @endforeach
</section>
