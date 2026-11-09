@php
    $showPreview = old('original_name') !== null || $errors->any();
@endphp

<x-layouts.app title="Create Product with AI">
    <x-page-header title="Create Product with AI" description="Describe your product, then review what Gemini finds before anything is saved." />

    <form method="POST" action="{{ route('products.store') }}" class="max-w-3xl space-y-6"
        data-product-analysis
        data-analyze-url="{{ route('ai.products.analyze') }}"
        data-csrf="{{ csrf_token() }}">
        @csrf
        <input type="hidden" name="status" value="draft">

        <section class="card space-y-4 p-5 sm:p-6">
            <x-form.textarea name="prompt" label="Describe your product" :value="old('prompt')" rows="6" maxlength="5000" data-analysis-prompt
                placeholder="Example: I have a 3D printed gaming controller stand for PS5 controllers, made from PLA, available in multiple colors, designed to organize gaming desks." />

            <div data-analysis-error hidden role="alert" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 ring-1 ring-red-200"></div>

            <div data-analysis-start @if ($showPreview) hidden @endif class="flex justify-end">
                <button type="button" data-analysis-analyze class="btn btn-primary">
                    <x-icon name="sparkles" class="size-4" data-analysis-idle-icon />
                    <x-icon name="spinner" class="size-4 animate-spin" data-analysis-busy-icon hidden />
                    <span data-analysis-label>Analyze with AI</span>
                </button>
            </div>
        </section>

        <section data-analysis-preview @unless ($showPreview) hidden @endunless class="card space-y-4 p-5 sm:p-6">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h2 class="text-base font-semibold">Review product information</h2>
                    <p class="mt-1 text-sm text-gray-500">Correct anything you need. Nothing is saved until you click Create Product.</p>
                </div>
                <x-status-badge :status="App\Enums\ProductStatus::Draft" />
            </div>

            @error('form')
                <div role="alert" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 ring-1 ring-red-200">{{ $message }}</div>
            @enderror

            <x-form.input name="original_name" label="Product name" :value="old('original_name')" required maxlength="255" data-analysis-field="original_name" />

            <div class="grid gap-4 sm:grid-cols-2">
                <x-form.input name="sku" label="SKU" :value="old('sku')" maxlength="100" placeholder="Not provided" data-analysis-field="sku" />
                <x-form.input name="category" label="Category" :value="old('category')" maxlength="120" placeholder="Not identified" list="category-options" data-analysis-field="category" />
            </div>

            <datalist id="category-options">
                @foreach ($categories as $category)
                    <option value="{{ $category }}"></option>
                @endforeach
            </datalist>

            <x-form.input name="material" label="Material" :value="old('material')" maxlength="120" placeholder="Not mentioned" data-analysis-field="material" />
            <x-form.textarea name="details" label="Product details" :value="old('details')" rows="4" maxlength="10000" data-analysis-field="details" />

            <div class="flex flex-wrap items-center justify-end gap-3">
                <p class="mr-auto text-xs text-gray-500">Analyzing again uses one AI request and replaces these fields.</p>
                <button type="button" data-analysis-again class="btn btn-secondary">
                    <x-icon name="spinner" class="size-4 animate-spin" data-analysis-busy-icon hidden />
                    Analyze Again
                </button>
                <button type="submit" data-analysis-create class="btn btn-primary">Create Product</button>
            </div>
        </section>
    </form>
</x-layouts.app>
