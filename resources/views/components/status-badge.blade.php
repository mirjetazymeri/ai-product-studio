@props(['status'])

<span @class([
    'inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset',
    'bg-emerald-50 text-emerald-700 ring-emerald-200' => $status === \App\Enums\ProductStatus::Published,
    'bg-gray-100 text-gray-600 ring-gray-200' => $status === \App\Enums\ProductStatus::Draft,
])>{{ $status->label() }}</span>
