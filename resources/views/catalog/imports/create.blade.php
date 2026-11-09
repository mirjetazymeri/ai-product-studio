<x-layouts.app title="Import Products">
    <x-page-header title="Import Products" description="Upload a file, match its columns, and review everything before products are created." />

    <form method="POST" action="{{ route('catalog.imports.store') }}" enctype="multipart/form-data" data-single-submit class="card max-w-2xl space-y-4 p-5 sm:p-6">
        @csrf

        <div>
            <label for="file" class="mb-1.5 block text-sm font-medium text-gray-700">CSV or Excel file</label>
            <input id="file" name="file" type="file" accept=".csv,.xlsx" required class="field file:mr-3 file:rounded-md file:border-0 file:bg-indigo-50 file:px-3 file:py-1 file:text-sm file:font-medium file:text-indigo-700">
            @error('file')
                <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>
            @else
                <p class="mt-1.5 text-xs text-gray-500">.csv or .xlsx, up to {{ round(config('catalog.max_file_kb') / 1024) }} MB and {{ number_format(config('catalog.max_rows')) }} rows. The first row must contain column names.</p>
            @enderror
        </div>

        <p class="text-sm text-gray-500">To include translations, use one row per product and language, with a Language column containing en, sq or de.</p>

        <div class="flex justify-end gap-3">
            <a href="{{ route('products.index') }}" class="btn btn-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary"><x-icon name="upload" class="size-4" /> Upload file</button>
        </div>
    </form>
</x-layouts.app>
