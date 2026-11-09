<x-layouts.app title="Generation History">
    <x-page-header title="Generation History" description="Every AI request made for your products." />

    <section class="grid gap-4 sm:grid-cols-3" aria-label="Usage">
        <x-stat-card label="Generations left today" :value="$stats['remaining']" icon="sparkles" />
        <x-stat-card label="Tokens used today" :value="$stats['tokensToday']" icon="clock" />
        <x-stat-card label="Tokens used in total" :value="$stats['tokensTotal']" icon="document" />
    </section>

    <div class="card">
        @if ($generations->isEmpty())
            <x-empty-state icon="clock" title="No generations yet" description="Your AI generations will be listed here once you create one.">
                <a href="{{ route('ai-studio') }}" class="btn btn-secondary">Open AI Studio</a>
            </x-empty-state>
        @else
            <ul class="divide-y divide-gray-100">
                @foreach ($generations as $generation)
                    <li class="p-4 sm:px-5">
                        <div class="flex items-start justify-between gap-4">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium">
                                    @if ($generation->product)
                                        <a href="{{ route('products.show', $generation->product) }}" class="hover:text-indigo-600">{{ $generation->product_name }}</a>
                                    @else
                                        {{ $generation->product_name }} <span class="font-normal text-gray-500">(deleted)</span>
                                    @endif
                                </p>
                                <p class="mt-0.5 text-xs text-gray-500">
                                    {{ $generation->language->label() }}
                                    · {{ ucfirst($generation->provider) }} {{ $generation->model }}
                                    · {{ $generation->created_at->format('M j, Y, H:i') }}
                                    @if ($generation->total_tokens !== null)
                                        · {{ number_format($generation->total_tokens) }} tokens
                                        ({{ number_format((int) $generation->input_tokens) }} in, {{ number_format((int) $generation->output_tokens) }} out)
                                    @endif
                                </p>
                                @if ($generation->preferences)
                                    <p class="mt-0.5 text-xs text-gray-500">{{ App\Services\AI\GenerationPreferences::fromHistory($generation->preferences)->summary() }}</p>
                                @endif
                            </div>

                            <div class="flex shrink-0 flex-wrap items-center justify-end gap-1.5">
                                @if ($generation->is_regeneration)
                                    <span class="rounded-md bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600">Regenerated</span>
                                @endif
                                @if ($generation->saved_at)
                                    <span class="rounded-md bg-indigo-50 px-2 py-0.5 text-xs font-medium text-indigo-700">Saved</span>
                                @endif
                                <span @class([
                                    'rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset',
                                    'bg-emerald-50 text-emerald-700 ring-emerald-200' => $generation->status === App\Enums\AiGenerationStatus::Succeeded,
                                    'bg-red-50 text-red-700 ring-red-200' => $generation->status === App\Enums\AiGenerationStatus::Failed,
                                    'bg-gray-100 text-gray-600 ring-gray-200' => $generation->status === App\Enums\AiGenerationStatus::Pending,
                                ])>{{ $generation->status->label() }}</span>
                            </div>
                        </div>

                        @if ($generation->status === App\Enums\AiGenerationStatus::Failed && $generation->errorMessage())
                            <p class="mt-2 text-sm text-red-700">{{ $generation->errorMessage() }}</p>
                        @endif

                        @if ($generation->generated_content)
                            <details class="mt-2">
                                <summary class="text-sm font-medium text-indigo-600 hover:text-indigo-700">Preview output</summary>
                                <dl class="mt-3 space-y-2.5 rounded-lg bg-gray-50 p-4 text-sm">
                                    @foreach (['title' => 'Title', 'short_description' => 'Short description', 'long_description' => 'Long description', 'meta_title' => 'Meta title', 'meta_description' => 'Meta description', 'slug' => 'URL slug'] as $field => $label)
                                        <div>
                                            <dt class="text-xs font-medium text-gray-500">{{ $label }}</dt>
                                            <dd class="mt-0.5 break-words whitespace-pre-line">{{ $generation->generated_content[$field] ?? '—' }}</dd>
                                        </div>
                                    @endforeach
                                    <div>
                                        <dt class="text-xs font-medium text-gray-500">Keywords</dt>
                                        <dd class="mt-0.5 break-words">{{ implode(', ', $generation->generated_content['keywords'] ?? []) }}</dd>
                                    </div>
                                </dl>
                            </details>
                        @endif
                    </li>
                @endforeach
            </ul>

            @if ($generations->hasPages())
                <div class="border-t border-gray-200 px-4 py-3">{{ $generations->links() }}</div>
            @endif
        @endif
    </div>
</x-layouts.app>
