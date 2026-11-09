@props(['status'])

<span @class([
    'inline-flex rounded-md px-2 py-0.5 text-xs font-medium whitespace-nowrap ring-1 ring-inset',
    'bg-emerald-50 text-emerald-700 ring-emerald-200' => $status === App\Enums\CatalogTransferStatus::Completed,
    'bg-amber-50 text-amber-700 ring-amber-200' => $status === App\Enums\CatalogTransferStatus::CompletedWithErrors,
    'bg-red-50 text-red-700 ring-red-200' => $status === App\Enums\CatalogTransferStatus::Failed,
    'bg-indigo-50 text-indigo-700 ring-indigo-200' => $status->isRunning(),
    'bg-gray-100 text-gray-600 ring-gray-200' => in_array($status, [App\Enums\CatalogTransferStatus::Uploaded, App\Enums\CatalogTransferStatus::Validated], true),
])>{{ $status->label() }}</span>
