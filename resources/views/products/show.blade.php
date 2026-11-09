@php
    $activeLanguage = collect($languages)->first(fn ($language) => $product->contentFor($language) !== null) ?? $languages[0];
@endphp

<x-layouts.app :title="$product->original_name">
    <a href="{{ route('products.index') }}" class="inline-flex items-center gap-1.5 text-sm font-medium text-gray-500 hover:text-gray-900">
        <x-icon name="arrow-left" class="size-4" /> Products
    </a>

    <x-page-header :title="$product->original_name">
        <x-slot:actions>
            <x-status-badge :status="$product->status" />
            <a href="{{ route('products.edit', $product) }}" class="btn btn-secondary"><x-icon name="pencil" class="size-4" /> Edit</a>
            <form method="POST" action="{{ route('products.destroy', $product) }}" data-confirm="This permanently deletes “{{ $product->original_name }}” and all of its content.">
                @csrf
                @method('DELETE')
                <button type="submit" class="btn btn-danger-soft"><x-icon name="trash" class="size-4" /> Delete</button>
            </form>
        </x-slot:actions>
    </x-page-header>

    @if ($product->isDemo())
        <x-demo-banner>This is a local copy of a sample product, and it isn't part of your real catalog.</x-demo-banner>
    @endif

    <div class="grid items-start gap-6 lg:grid-cols-3">
        <section class="card lg:col-span-2" data-tabs>
            <div class="px-5 pt-5 sm:px-6 sm:pt-6">
                <h2 class="text-base font-semibold">Content</h2>

                <div role="tablist" aria-label="Content language" class="mt-4 flex gap-1 overflow-x-auto shadow-[inset_0_-1px_0_var(--color-gray-200)]">
                    @foreach ($languages as $language)
                        <button type="button" role="tab" id="tab-{{ $language->value }}" aria-controls="panel-{{ $language->value }}"
                            aria-selected="{{ $language === $activeLanguage ? 'true' : 'false' }}"
                            tabindex="{{ $language === $activeLanguage ? 0 : -1 }}"
                            class="flex items-center gap-2 border-b-2 border-transparent px-3 py-2 text-sm font-medium whitespace-nowrap text-gray-500 transition-colors hover:text-gray-900 aria-selected:border-indigo-600 aria-selected:text-indigo-600">
                            {{ $language->label() }}
                            @if ($product->contentFor($language))
                                <span class="size-1.5 rounded-full bg-emerald-500"></span>
                                <span class="sr-only">(has content)</span>
                            @endif
                        </button>
                    @endforeach
                </div>
            </div>

            @foreach ($languages as $language)
                @php($content = $product->contentFor($language))

                <div role="tabpanel" id="panel-{{ $language->value }}" aria-labelledby="tab-{{ $language->value }}" @if ($language !== $activeLanguage) hidden @endif>
                    @if ($content === null)
                        <x-empty-state icon="document" title="No {{ $language->label() }} content" description="Add a title, descriptions and SEO metadata for this language.">
                            <a href="{{ route('products.edit', $product) }}" class="btn btn-secondary">Add content</a>
                        </x-empty-state>
                    @else
                        <div class="space-y-5 p-5 sm:p-6">
                            <div>
                                <h3 class="text-lg font-semibold tracking-tight break-words">{{ $content->title ?? 'Untitled' }}</h3>
                                <p class="mt-1 text-xs text-gray-500">{{ $content->source->label() }}</p>
                            </div>

                            @if ($content->short_description)
                                <div>
                                    <h4 class="text-xs font-medium text-gray-500">Short description</h4>
                                    <p class="mt-1 text-sm break-words whitespace-pre-line">{{ $content->short_description }}</p>
                                </div>
                            @endif

                            @if ($content->long_description)
                                <div>
                                    <h4 class="text-xs font-medium text-gray-500">Long description</h4>
                                    <p class="mt-1 text-sm leading-relaxed break-words whitespace-pre-line">{{ $content->long_description }}</p>
                                </div>
                            @endif

                            <div class="border-t border-gray-100 pt-5">
                                <h4 class="text-sm font-semibold">SEO metadata</h4>

                                <dl class="mt-3 space-y-3 text-sm">
                                    <div>
                                        <dt class="text-xs font-medium text-gray-500">Meta title</dt>
                                        <dd class="mt-0.5 break-words">{{ $content->meta_title ?? '—' }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-xs font-medium text-gray-500">Meta description</dt>
                                        <dd class="mt-0.5 break-words">{{ $content->meta_description ?? '—' }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-xs font-medium text-gray-500">URL slug</dt>
                                        <dd class="mt-0.5 font-mono text-xs break-all">{{ $content->slug ?? '—' }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-xs font-medium text-gray-500">Keywords</dt>
                                        <dd class="mt-1 flex flex-wrap gap-1.5">
                                            @forelse ($content->keywords ?? [] as $keyword)
                                                <span class="rounded-md bg-gray-100 px-2 py-0.5 text-xs text-gray-700">{{ $keyword }}</span>
                                            @empty
                                                —
                                            @endforelse
                                        </dd>
                                    </div>
                                </dl>
                            </div>
                        </div>
                    @endif
                </div>
            @endforeach
        </section>

        <div class="space-y-6">
            <section class="card p-5">
                <h2 class="text-sm font-semibold">Product information</h2>

                <dl class="mt-4 space-y-3 text-sm">
                    @foreach (['SKU' => $product->sku, 'Category' => $product->category, 'Material' => $product->material] as $label => $value)
                        <div class="flex justify-between gap-4">
                            <dt class="text-gray-500">{{ $label }}</dt>
                            <dd class="min-w-0 text-right break-words">{{ $value ?? '—' }}</dd>
                        </div>
                    @endforeach

                    <div class="flex justify-between gap-4">
                        <dt class="text-gray-500">Created</dt>
                        <dd class="text-right">{{ $product->created_at->format('M j, Y, H:i') }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-gray-500">Updated</dt>
                        <dd class="text-right">{{ $product->updated_at->format('M j, Y, H:i') }}</dd>
                    </div>
                </dl>

                @if ($product->details)
                    <div class="mt-4 border-t border-gray-100 pt-4">
                        <h3 class="text-xs font-medium text-gray-500">Details</h3>
                        <p class="mt-1 text-sm break-words whitespace-pre-line">{{ $product->details }}</p>
                    </div>
                @endif
            </section>

            @if ($product->isDemo())
                <section class="card p-5">
                    <h2 class="text-sm font-semibold">Demo content</h2>
                    <p class="mt-1 text-sm text-gray-500">Sample AI Output and the optional real Gemini request are on the demo product page.</p>
                    @if ($demoLink)
                        <a href="{{ route('integrations.demo.product', [$product->demo_store_id, $demoLink->remote_id]) }}" class="btn btn-primary mt-4 w-full">Open demo product</a>
                    @endif
                </section>
            @else
                <section class="card p-5">
                    <h2 class="text-sm font-semibold">AI generation</h2>
                    <p class="mt-1 text-sm text-gray-500">Draft this product's content with Gemini, then review it before saving.</p>
                    <button type="button" data-ai-open class="btn btn-primary mt-4 w-full"><x-icon name="sparkles" class="size-4" /> Generate content</button>
                </section>
            @endif
        </div>
    </div>

    @unless ($product->isDemo())
        {{-- The dialog is the only scrolling area. Its header and each step's footer stick to its edges. --}}
        <dialog id="ai-dialog" aria-labelledby="ai-dialog-title" class="m-auto max-h-[90dvh] w-[calc(100%-2rem)] max-w-3xl overflow-x-hidden overflow-y-auto rounded-xl bg-white text-gray-900 shadow-xl">
            <div class="sticky top-0 z-20 flex items-center justify-between gap-4 border-b border-gray-200 bg-white px-5 py-3 sm:px-6">
                <h2 id="ai-dialog-title" class="text-base font-semibold">Generate content</h2>
                <button type="button" data-ai-close class="rounded-md p-1 text-gray-500 hover:bg-gray-100" aria-label="Close">
                    <x-icon name="close" />
                </button>
            </div>

            <div class="px-5 pt-5 sm:px-6">
                @include('ai.partials.panel')
            </div>
        </dialog>
    @endunless
</x-layouts.app>
