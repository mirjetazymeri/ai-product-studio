<x-layouts.app :title="'Edit '.$product->original_name">
    <x-page-header :title="'Edit '.$product->original_name" />

    <form method="POST" action="{{ route('products.update', $product) }}" class="space-y-6">
        @csrf
        @method('PUT')
        @include('products.partials.form')

        <div class="flex justify-end gap-3">
            <a href="{{ route('products.show', $product) }}" class="btn btn-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary">Save changes</button>
        </div>
    </form>

    <section class="card flex flex-col gap-4 p-5 sm:flex-row sm:items-center sm:justify-between sm:p-6">
        <div>
            <h2 class="text-base font-semibold">Delete this product</h2>
            <p class="mt-1 text-sm text-gray-500">This also deletes its content in every language and can't be undone.</p>
        </div>
        <form method="POST" action="{{ route('products.destroy', $product) }}" data-confirm="This permanently deletes “{{ $product->original_name }}” and all of its content.">
            @csrf
            @method('DELETE')
            <button type="submit" class="btn btn-danger-soft"><x-icon name="trash" class="size-4" /> Delete product</button>
        </form>
    </section>
</x-layouts.app>
