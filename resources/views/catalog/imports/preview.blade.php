<x-layouts.app title="Import Preview">
    <x-page-header title="Import preview" :description="$transfer->original_name.'. No products have been created yet.'">
        <x-slot:actions>
            <a href="{{ route('catalog.imports.mapping', $transfer) }}" class="btn btn-secondary">Change column mapping</a>
        </x-slot:actions>
    </x-page-header>

    <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4" aria-label="Validation summary">
        <x-stat-card label="Total rows" :value="$summary['total_rows']" icon="document" />
        <x-stat-card label="Valid rows" :value="$summary['valid_rows']" icon="check" />
        <x-stat-card label="Invalid rows" :value="$summary['invalid_rows']" icon="close" />
        <x-stat-card label="Possible duplicates" :value="$summary['possible_duplicates']" icon="search" />
    </section>

    <p class="text-sm text-gray-500">{{ number_format($summary['products']) }} products found. Rows for the same product in different languages are combined.</p>

    <div class="grid items-start gap-6 lg:grid-cols-3">
        <section class="card p-5 lg:col-span-1">
            <h2 class="text-sm font-semibold">Mapped columns</h2>
            <dl class="mt-3 space-y-2 text-sm">
                @foreach ($mapped as $column)
                    <div class="flex justify-between gap-4">
                        <dt class="min-w-0 break-words text-gray-500">{{ $column['source'] }}</dt>
                        <dd class="shrink-0 text-right">{{ $column['destination'] }}</dd>
                    </div>
                @endforeach
            </dl>
        </section>

        <form method="POST" action="{{ route('catalog.imports.confirm', $transfer) }}" data-single-submit data-import-strategy class="card space-y-4 p-5 sm:p-6 lg:col-span-2">
            @csrf
            <h2 class="text-sm font-semibold">When a product already exists</h2>

            @if ($errors->any())
                <div role="alert" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 ring-1 ring-red-200">{{ $errors->first() }}</div>
            @endif

            <div class="space-y-2">
                @foreach ($strategies as $strategy)
                    <label class="flex items-center gap-2 text-sm">
                        <input type="radio" name="strategy" value="{{ $strategy->value }}" class="size-4 accent-indigo-600" @checked(old('strategy', 'skip') === $strategy->value)>
                        {{ $strategy->label() }}
                    </label>
                @endforeach
            </div>

            <fieldset data-strategy-panel="update" hidden class="space-y-3 rounded-lg bg-gray-50 p-4">
                <legend class="sr-only">Fields to update</legend>
                <p class="text-sm font-medium">Fields to update</p>
                <div class="grid gap-2 sm:grid-cols-2">
                    @foreach ($updatableFields as $field => $label)
                        <label class="flex items-center gap-2 text-sm">
                            <input type="checkbox" name="update_fields[]" value="{{ $field }}" class="size-4 rounded accent-indigo-600" @checked(in_array($field, old('update_fields', []), true))>
                            {{ $label }}
                        </label>
                    @endforeach
                </div>
                <p class="text-xs text-gray-500">Blank cells never erase existing data. Products matched only by name aren't updated.</p>
                <label class="flex items-start gap-2 text-sm text-amber-800">
                    <input type="checkbox" name="confirm_update" value="1" class="mt-0.5 size-4 rounded accent-indigo-600">
                    I understand this changes existing products and can't be undone.
                </label>
            </fieldset>

            <fieldset data-strategy-panel="create_new" hidden class="rounded-lg bg-gray-50 p-4">
                <legend class="sr-only">Approve importing as new</legend>
                <label class="flex items-start gap-2 text-sm text-amber-800">
                    <input type="checkbox" name="approve_new" value="1" class="mt-0.5 size-4 rounded accent-indigo-600">
                    Create products with the same name as an existing one. Rows with a SKU or external ID that's already in use are still skipped.
                </label>
            </fieldset>

            <div class="flex justify-end">
                <button type="submit" class="btn btn-primary" @disabled($summary['products'] === 0)>Confirm import of {{ number_format($summary['products']) }} products</button>
            </div>
        </form>
    </div>

    @if ($invalidRows->isNotEmpty())
        <section class="card">
            <div class="flex items-center justify-between gap-4 border-b border-gray-200 px-5 py-4">
                <h2 class="text-sm font-semibold">Rows that won't be imported</h2>
                <a href="{{ route('catalog.imports.errors', $transfer) }}" class="btn btn-secondary"><x-icon name="download" class="size-4" /> Download errors</a>
            </div>
            <ul class="divide-y divide-gray-100">
                @foreach ($invalidRows as $row)
                    <li class="px-5 py-3 text-sm">
                        <span class="font-medium">Row {{ $row->first_row }}</span>
                        <span class="text-gray-500">{{ $row->payload['product']['original_name'] ?? '' }}</span>
                        <p class="mt-0.5 text-red-700">{{ implode(' ', $row->errors ?? []) }}</p>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    @if ($validRows->isNotEmpty())
        <section class="card">
            <h2 class="border-b border-gray-200 px-5 py-4 text-sm font-semibold">First products to import</h2>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-gray-200 text-xs text-gray-500">
                        <tr>
                            <th scope="col" class="px-4 py-3 font-medium">Row</th>
                            <th scope="col" class="px-4 py-3 font-medium">Product</th>
                            <th scope="col" class="px-4 py-3 font-medium">SKU</th>
                            <th scope="col" class="px-4 py-3 font-medium">Category</th>
                            <th scope="col" class="px-4 py-3 font-medium">Languages</th>
                            <th scope="col" class="px-4 py-3 font-medium">Existing</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($validRows as $row)
                            <tr>
                                <td class="px-4 py-3 text-gray-500">{{ $row->first_row }}</td>
                                <td class="max-w-64 truncate px-4 py-3 font-medium">{{ $row->payload['product']['original_name'] }}</td>
                                <td class="px-4 py-3 text-gray-500">{{ $row->payload['product']['sku'] ?? '—' }}</td>
                                <td class="px-4 py-3 text-gray-500">{{ $row->payload['product']['category'] ?? '—' }}</td>
                                <td class="px-4 py-3 text-gray-500 uppercase">{{ implode(', ', array_keys($row->payload['contents'] ?? [])) ?: '—' }}</td>
                                <td class="px-4 py-3 whitespace-nowrap text-amber-700">{{ $row->match_type ? 'Matches by '.$row->match_type : '' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif
</x-layouts.app>
