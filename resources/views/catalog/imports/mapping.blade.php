<x-layouts.app title="Map Columns">
    <x-page-header title="Map columns" :description="$transfer->original_name" />

    <form method="POST" action="{{ route('catalog.imports.mapping.save', $transfer) }}" data-single-submit class="max-w-4xl space-y-6">
        @csrf

        @if ($errors->any())
            <div role="alert" class="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-red-200">
                <ul class="list-disc space-y-1 pl-4">
                    @foreach ($errors->all() as $message)
                        <li>{{ $message }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <section class="card">
            <div class="border-b border-gray-200 px-5 py-4">
                <h2 class="text-sm font-semibold">Match each column to a product field</h2>
                <p class="mt-0.5 text-xs text-gray-500">Suggestions are based on the column names. Product Name is required, and columns set to "Don't import" are ignored.</p>
            </div>

            <ul class="divide-y divide-gray-100">
                @foreach ($transfer->options['headers'] as $index => $header)
                    @php($current = old("mapping.{$index}", $selected[$index] ?? null))
                    <li class="grid gap-2 px-5 py-3 sm:grid-cols-2 sm:items-center sm:gap-6">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-medium">{{ $header }}</p>
                            <p class="truncate text-xs text-gray-500">
                                {{ collect($transfer->options['samples'])->pluck($index)->filter()->implode(' · ') ?: 'No sample values' }}
                            </p>
                        </div>
                        <div>
                            <label for="mapping-{{ $index }}" class="sr-only">Destination for {{ $header }}</label>
                            <select id="mapping-{{ $index }}" name="mapping[{{ $index }}]" class="field">
                                <option value="">Don't import</option>
                                @foreach ($destinations as $field => $label)
                                    <option value="{{ $field }}" @selected($current === $field)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>

        <section class="card grid gap-4 p-5 sm:grid-cols-2 sm:p-6">
            <div>
                <label for="default_language" class="mb-1.5 block text-sm font-medium text-gray-700">Language for rows without one</label>
                <select id="default_language" name="default_language" class="field">
                    @foreach ($languages as $language)
                        <option value="{{ $language->value }}" @selected(old('default_language', $transfer->options['default_language'] ?? 'en') === $language->value)>{{ $language->label() }}</option>
                    @endforeach
                </select>
            </div>
            <x-form.input name="source" label="External ID source name" :value="old('source', $transfer->options['source'] ?? 'file')" maxlength="50" hint="Names where the external IDs come from, such as old-shop. Used only with an External Product ID column." />
        </section>

        <div class="flex justify-end gap-3">
            <a href="{{ route('catalog.imports.create') }}" class="btn btn-secondary">Upload a different file</a>
            <button type="submit" class="btn btn-primary">Validate and preview</button>
        </div>
    </form>
</x-layouts.app>
