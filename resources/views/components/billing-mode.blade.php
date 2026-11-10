@props(['mode'])

<span {{ $attributes->class([
    'inline-block rounded-md px-2 py-0.5 text-xs font-medium whitespace-nowrap ring-1 ring-inset',
    'bg-amber-50 text-amber-800 ring-amber-200' => $mode !== App\Enums\BillingMode::StripeLive,
    'bg-emerald-50 text-emerald-700 ring-emerald-200' => $mode === App\Enums\BillingMode::StripeLive,
]) }}>{{ $mode->label() }}</span>
