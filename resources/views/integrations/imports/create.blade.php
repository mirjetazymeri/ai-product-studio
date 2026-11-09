<x-layouts.app title="Import Products">
    <x-page-header :title="$store->isDemo() ? 'Import Demo Products' : 'Import Products'" :description="'From '.$store->name.'. This only reads from your store.'" />

    @if ($store->isDemo())
        <x-demo-banner>This reads the simulated sample catalog through the normal import.</x-demo-banner>
    @endif

    <form method="POST" action="{{ route('integrations.imports.store', $store) }}" data-single-submit class="card max-w-2xl space-y-4 p-5 sm:p-6">
        @csrf

        <div>
            <label for="language" class="mb-1.5 block text-sm font-medium text-gray-700">Language the store is written in</label>
            <select id="language" name="language" class="field">
                @foreach ($languages as $language)
                    <option value="{{ $language->value }}" @selected(old('language', $detectedLanguage ?? $store->content_language?->value ?? 'en') === $language->value)>{{ $language->label() }}</option>
                @endforeach
            </select>
            @error('language')
                <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>
            @else
                <p class="mt-1.5 text-xs text-gray-500">{{ $detectedLanguage ? 'Detected from the store.' : 'Imported text is saved in this language, and sync only updates this language.' }}</p>
            @enderror
        </div>

        <p class="text-sm text-gray-500">You'll review what was found before any product is added to this app.</p>

        <div class="flex justify-end gap-3">
            <a href="{{ route('integrations.index') }}" class="btn btn-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary"><x-icon name="download" class="size-4" /> {{ $store->isDemo() ? 'Import Demo Products' : 'Read products from store' }}</button>
        </div>
    </form>
</x-layouts.app>
