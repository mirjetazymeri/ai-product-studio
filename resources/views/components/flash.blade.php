@if (session('status'))
    <div {{ $attributes->merge(['class' => 'flex items-center gap-2 rounded-lg bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800 ring-1 ring-emerald-200']) }} role="status">
        <x-icon name="check" class="text-emerald-600" />
        {{ session('status') }}
    </div>
@endif
