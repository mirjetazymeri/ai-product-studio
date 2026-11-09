<x-layouts.app title="AI Studio">
    <x-page-header title="AI Studio" description="Generate product titles, descriptions and SEO metadata with Gemini.">
        @if ($product)
            <x-slot:actions>
                <a href="{{ route('products.show', $product) }}" class="btn btn-secondary">View product</a>
            </x-slot:actions>
        @endif
    </x-page-header>

    @if ($products->isEmpty())
        <div class="card">
            <x-empty-state icon="sparkles" title="Add a product first" description="AI Studio writes content for products in your catalogue.">
                <a href="{{ route('products.create') }}" class="btn btn-primary"><x-icon name="plus" class="size-4" /> New product</a>
            </x-empty-state>
        </div>
    @else
        <div class="max-w-3xl space-y-6">
            <form method="GET" action="{{ route('ai-studio') }}" class="card p-5 sm:p-6">
                <label for="product" class="mb-1.5 block text-sm font-medium text-gray-700">Product</label>
                <select id="product" name="product" data-auto-submit class="field">
                    <option value="">Choose a product</option>
                    @foreach ($products as $option)
                        <option value="{{ $option->id }}" @selected($product?->id === $option->id)>{{ $option->original_name }}{{ $option->sku ? ' ('.$option->sku.')' : '' }}</option>
                    @endforeach
                </select>
                <noscript><button type="submit" class="btn btn-secondary mt-3">Select</button></noscript>
            </form>

            @if ($product)
                <section class="card px-5 pt-5 sm:px-6 sm:pt-6">
                    @include('ai.partials.panel')
                </section>
            @else
                <div class="card">
                    <x-empty-state icon="sparkles" title="Choose a product to begin" description="Pick a product above, select a language, and review the draft before saving." />
                </div>
            @endif
        </div>
    @endif
</x-layouts.app>
