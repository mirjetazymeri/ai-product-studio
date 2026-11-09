<x-layouts.app title="Export Products">
    <x-page-header title="Export Products" :description="number_format($count).' products will be included.'" />

    <form method="POST" action="{{ route('catalog.exports.store') }}" data-single-submit class="card max-w-2xl space-y-5 p-5 sm:p-6">
        @csrf

        @foreach ((array) ($input['product_ids'] ?? []) as $id)
            <input type="hidden" name="product_ids[]" value="{{ $id }}">
        @endforeach
        @foreach (['all_matching', 'search', 'status'] as $name)
            @if (filled($input[$name] ?? null))
                <input type="hidden" name="{{ $name }}" value="{{ $input[$name] }}">
            @endif
        @endforeach

        @if ($errors->any())
            <div role="alert" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 ring-1 ring-red-200">{{ $errors->first() }}</div>
        @endif

        <fieldset>
            <legend class="mb-2 text-sm font-medium text-gray-700">File format</legend>
            <div class="flex gap-6">
                @foreach (['csv' => 'CSV', 'xlsx' => 'Excel (.xlsx)'] as $value => $label)
                    <label class="flex items-center gap-2 text-sm">
                        <input type="radio" name="format" value="{{ $value }}" class="size-4 accent-indigo-600" @checked(old('format', 'csv') === $value)>
                        {{ $label }}
                    </label>
                @endforeach
            </div>
        </fieldset>

        <fieldset>
            <legend class="mb-2 text-sm font-medium text-gray-700">What to include</legend>
            <div class="space-y-2.5">
                @foreach ($scopes as $scope)
                    <label class="flex items-start gap-2 text-sm">
                        <input type="radio" name="scope" value="{{ $scope->value }}" class="mt-0.5 size-4 accent-indigo-600" @checked(old('scope', 'approved') === $scope->value)>
                        <span>
                            {{ $scope->label() }}
                            <span class="block text-xs text-gray-500">{{ $scope->description() }}</span>
                        </span>
                    </label>
                @endforeach
            </div>
        </fieldset>

        <fieldset>
            <legend class="mb-2 text-sm font-medium text-gray-700">Languages</legend>
            <div class="flex flex-wrap gap-6">
                @foreach ($languages as $language)
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" name="languages[]" value="{{ $language->value }}" class="size-4 rounded accent-indigo-600" @checked(in_array($language->value, old('languages', App\Enums\ContentLanguage::values()), true))>
                        {{ $language->label() }}
                    </label>
                @endforeach
            </div>
        </fieldset>

        <p class="text-xs text-gray-500">One row per product and language, including the local product ID and any external reference.</p>

        <div class="flex justify-end gap-3">
            <a href="{{ route('products.index') }}" class="btn btn-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary" @disabled($count === 0)><x-icon name="download" class="size-4" /> Create export</button>
        </div>
    </form>
</x-layouts.app>
