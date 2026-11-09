<x-layouts.app title="Dashboard">
    <x-page-header :title="'Welcome back, '.strtok(auth()->user()->name, ' ')" description="Here's where your catalogue stands today.">
        <x-slot:actions>
            <a href="{{ route('products.create') }}" class="btn btn-primary"><x-icon name="plus" class="size-4" /> New product</a>
        </x-slot:actions>
    </x-page-header>

    <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4" aria-label="Statistics">
        <x-stat-card label="Total products" :value="$stats['total']" icon="cube" />
        <x-stat-card label="Drafts" :value="$stats['draft']" icon="document" />
        <x-stat-card label="Published" :value="$stats['published']" icon="check" />
        <x-stat-card label="AI generations" :value="$stats['generations']" icon="sparkles" />
    </section>

    <div class="grid gap-6 lg:grid-cols-3">
        <section class="card lg:col-span-2">
            <div class="flex items-center justify-between border-b border-gray-200 px-5 py-4">
                <h2 class="text-sm font-semibold">Recently created</h2>
                <a href="{{ route('products.index') }}" class="text-sm font-medium text-indigo-600 hover:text-indigo-700">View all</a>
            </div>

            @if ($recentProducts->isEmpty())
                <x-empty-state icon="cube" title="No products yet" description="Create your first product to start building your catalogue.">
                    <a href="{{ route('products.create') }}" class="btn btn-primary"><x-icon name="plus" class="size-4" /> New product</a>
                </x-empty-state>
            @else
                <ul class="divide-y divide-gray-100">
                    @foreach ($recentProducts as $product)
                        <li>
                            <a href="{{ route('products.show', $product) }}" class="flex items-center justify-between gap-4 px-5 py-3.5 transition-colors hover:bg-gray-50">
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-medium">{{ $product->original_name }}</p>
                                    <p class="mt-0.5 text-xs text-gray-500">Added {{ $product->created_at->diffForHumans() }}</p>
                                </div>
                                <x-status-badge :status="$product->status" />
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        <aside class="card p-5">
            <span class="grid size-10 place-items-center rounded-lg bg-indigo-50 text-indigo-600">
                <x-icon name="sparkles" />
            </span>
            <h2 class="mt-4 text-sm font-semibold">AI Studio</h2>
            <p class="mt-1 text-sm text-gray-500">
                Draft product content with Gemini in English, Albanian or German, and review it before saving.
            </p>
            <a href="{{ route('ai-studio') }}" class="btn btn-secondary mt-4">Open AI Studio</a>
        </aside>
    </div>
</x-layouts.app>
