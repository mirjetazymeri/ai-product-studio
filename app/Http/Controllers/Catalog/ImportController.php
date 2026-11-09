<?php

namespace App\Http\Controllers\Catalog;

use App\Enums\CatalogTransferStatus;
use App\Enums\ContentLanguage;
use App\Enums\ImportStrategy;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\ConfirmImportRequest;
use App\Http\Requests\Catalog\SaveColumnMappingRequest;
use App\Http\Requests\Catalog\UploadCatalogFileRequest;
use App\Jobs\ProcessCatalogImport;
use App\Models\CatalogImportRow;
use App\Models\CatalogTransfer;
use App\Services\Catalog\CatalogImporter;
use App\Services\Catalog\CellSanitizer;
use App\Services\Catalog\ColumnMapper;
use App\Services\Catalog\Exceptions\CatalogFileException;
use App\Services\Catalog\ImportAnalyzer;
use App\Services\Catalog\SpreadsheetReader;
use App\Services\WooCommerce\WooCommerceProductImporter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ImportController extends Controller
{
    private const SAMPLE_ROWS = 3;

    public function create(): View
    {
        return view('catalog.imports.create');
    }

    public function store(UploadCatalogFileRequest $request, SpreadsheetReader $reader, ColumnMapper $mapper): RedirectResponse
    {
        $file = $request->file('file');
        $type = strtolower($file->getClientOriginalExtension());
        $path = $file->storeAs("catalog/imports/{$request->user()->id}", Str::uuid().'.'.$type, 'local');

        try {
            $absolute = Storage::disk('local')->path($path);
            $headers = $reader->headers($absolute, $type);
            $samples = $this->samples($reader, $absolute, $type);
        } catch (CatalogFileException $exception) {
            Storage::disk('local')->delete($path);

            return back()->withErrors(['file' => $exception->getMessage()]);
        }

        $transfer = $request->user()->catalogTransfers()->create([
            'operation' => CatalogTransfer::IMPORT,
            'file_type' => $type,
            'original_name' => Str::limit($file->getClientOriginalName(), 200, ''),
            'stored_path' => $path,
            'status' => CatalogTransferStatus::Uploaded,
            'options' => ['headers' => $headers, 'samples' => $samples, 'suggested' => $mapper->suggest($headers)],
            'expires_at' => now()->addDays((int) config('catalog.retention_days')),
        ]);

        return to_route('catalog.imports.mapping', $transfer);
    }

    public function mapping(CatalogTransfer $transfer): View|RedirectResponse
    {
        $this->authorizeImport($transfer);

        if (! $this->isEditable($transfer)) {
            return to_route('catalog.imports.show', $transfer);
        }

        return view('catalog.imports.mapping', [
            'transfer' => $transfer,
            'destinations' => ColumnMapper::destinations(),
            'selected' => $transfer->options['mapping'] ?? $transfer->options['suggested'],
            'languages' => ContentLanguage::cases(),
        ]);
    }

    public function saveMapping(SaveColumnMappingRequest $request, CatalogTransfer $transfer, ImportAnalyzer $analyzer): RedirectResponse
    {
        $this->authorizeImport($transfer);

        if (! $this->isEditable($transfer)) {
            return to_route('catalog.imports.show', $transfer);
        }

        try {
            $analyzer->analyze($transfer, $request->columnMap(), $request->source(), $request->defaultLanguage());
        } catch (CatalogFileException $exception) {
            return back()->withInput()->withErrors(['mapping' => $exception->getMessage()]);
        }

        return to_route('catalog.imports.preview', $transfer);
    }

    public function preview(CatalogTransfer $transfer): View|RedirectResponse
    {
        $this->authorizeImport($transfer);

        if ($transfer->status === CatalogTransferStatus::Uploaded) {
            return to_route('catalog.imports.mapping', $transfer);
        }

        if ($transfer->status !== CatalogTransferStatus::Validated) {
            return to_route('catalog.imports.show', $transfer);
        }

        $destinations = ColumnMapper::destinations();
        $headers = $transfer->options['headers'];

        return view('catalog.imports.preview', [
            'transfer' => $transfer,
            'summary' => $transfer->summary,
            'mapped' => collect($transfer->options['mapping'])->map(fn (string $field, int $index) => ['source' => $headers[$index], 'destination' => $destinations[$field]])->values(),
            'validRows' => $transfer->rows()->where('status', CatalogImportRow::VALID)->orderBy('first_row')->limit(10)->get(),
            'invalidRows' => $transfer->rows()->where('status', CatalogImportRow::INVALID)->orderBy('first_row')->limit(50)->get(),
            'strategies' => ImportStrategy::cases(),
            'updatableFields' => CatalogImporter::UPDATABLE_FIELDS,
        ]);
    }

    public function confirm(ConfirmImportRequest $request, CatalogTransfer $transfer, CatalogImporter $importer): RedirectResponse
    {
        $this->authorizeImport($transfer);

        if ((int) ($transfer->summary['products'] ?? 0) === 0) {
            return back()->withErrors(['strategy' => 'There are no valid rows to import.']);
        }

        // A second submission finds the import already claimed and just shows its progress.
        if ($importer->confirm($transfer, $request->strategy(), $request->updateFields())) {
            $this->run($transfer, $importer);
        }

        return to_route('catalog.imports.show', $transfer);
    }

    public function show(CatalogTransfer $transfer): View|RedirectResponse
    {
        $this->authorizeImport($transfer);

        if ($this->isEditable($transfer)) {
            return to_route('catalog.imports.mapping', $transfer);
        }

        return view('catalog.imports.show', [
            'transfer' => $transfer,
            'problemRows' => $transfer->rows()
                ->where(fn ($query) => $query->where('status', CatalogImportRow::INVALID)->orWhere('result', CatalogImportRow::FAILED))
                ->orderBy('first_row')
                ->limit(50)
                ->get(),
            'processed' => (int) $transfer->rows()->whereNotNull('result')->sum('row_count'),
            'pending' => (int) $transfer->rows()->where('status', CatalogImportRow::VALID)->sum('row_count'),
        ]);
    }

    public function errors(CatalogTransfer $transfer): StreamedResponse
    {
        $this->authorizeImport($transfer);

        return response()->streamDownload(function () use ($transfer) {
            $output = fopen('php://output', 'wb');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, ['row', 'product', 'error'], escape: '');

            $transfer->rows()
                ->where(fn ($query) => $query->where('status', CatalogImportRow::INVALID)->orWhere('result', CatalogImportRow::FAILED))
                ->orderBy('first_row')
                ->each(function (CatalogImportRow $row) use ($output) {
                    fputcsv($output, [
                        $row->first_row,
                        CellSanitizer::forExport($row->payload['product']['original_name'] ?? ''),
                        CellSanitizer::forExport(implode(' ', $row->errors ?? [(string) $row->result_message])),
                    ], escape: '');
                });

            fclose($output);
        }, 'import-errors-'.$transfer->id.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function retry(Request $request, CatalogTransfer $transfer, CatalogImporter $importer): RedirectResponse
    {
        $this->authorizeImport($transfer);

        $claimed = CatalogTransfer::query()
            ->whereKey($transfer->id)
            ->whereIn('status', [CatalogTransferStatus::Failed->value, CatalogTransferStatus::Queued->value])
            ->update(['status' => CatalogTransferStatus::Queued->value, 'error_summary' => null, 'completed_at' => null]);

        if ($claimed === 1) {
            $this->run($transfer->refresh(), $importer);
        }

        return to_route('catalog.imports.show', $transfer);
    }

    private function run(CatalogTransfer $transfer, CatalogImporter $importer): void
    {
        $remaining = $transfer->rows()->where('status', CatalogImportRow::VALID)->whereNull('result')->count();

        if ($remaining > config('catalog.import_sync_limit')) {
            ProcessCatalogImport::dispatch($transfer->id);

            return;
        }

        try {
            ProcessCatalogImport::dispatchSync($transfer->id);
        } catch (Throwable $exception) {
            $importer->markFailed($transfer->refresh(), $exception);
        }
    }

    private function authorizeImport(CatalogTransfer $transfer): void
    {
        Gate::authorize('view', $transfer);

        // Store imports have no file or column mapping, and have their own pages.
        abort_unless($transfer->isImport() && $transfer->file_type !== WooCommerceProductImporter::FILE_TYPE, 404);
    }

    private function isEditable(CatalogTransfer $transfer): bool
    {
        return in_array($transfer->status, [CatalogTransferStatus::Uploaded, CatalogTransferStatus::Validated], true);
    }

    /**
     * @return list<array<int, ?string>>
     *
     * @throws CatalogFileException
     */
    private function samples(SpreadsheetReader $reader, string $path, string $type): array
    {
        $samples = [];

        foreach ($reader->rows($path, $type) as $cells) {
            if (array_filter($cells, fn ($cell) => $cell !== null) === []) {
                continue;
            }

            $samples[] = array_map(fn (?string $cell) => $cell === null ? null : Str::limit($cell, 80), $cells);

            if (count($samples) === self::SAMPLE_ROWS) {
                break;
            }
        }

        if ($samples === []) {
            throw new CatalogFileException('This file has no data rows.');
        }

        return $samples;
    }
}
