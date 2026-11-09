<?php

namespace App\Http\Controllers\Catalog;

use App\Enums\ContentLanguage;
use App\Enums\ExportContentScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreExportRequest;
use App\Jobs\GenerateCatalogExport;
use App\Models\CatalogTransfer;
use App\Services\Catalog\CatalogExporter;
use App\Services\Catalog\ProductSelection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportController extends Controller
{
    public function create(Request $request, ProductSelection $selection): View
    {
        $input = $request->only(['product_ids', 'all_matching', 'search', 'status']);

        return view('catalog.exports.create', [
            'input' => $input,
            'count' => $selection->query($request->user(), $input)->count(),
            'scopes' => ExportContentScope::cases(),
            'languages' => ContentLanguage::cases(),
        ]);
    }

    public function store(StoreExportRequest $request, CatalogExporter $exporter, ProductSelection $selection): RedirectResponse
    {
        $input = $request->selection();
        $count = $selection->query($request->user(), $input)->count();

        if ($count === 0) {
            return back()->withInput()->withErrors(['format' => 'There are no products to export.']);
        }

        $transfer = $exporter->createTransfer(
            $request->user(),
            $request->validated('format'),
            ExportContentScope::from($request->validated('scope')),
            $request->validated('languages') ?? ContentLanguage::values(),
            $input,
        );

        if ($count > config('catalog.export_sync_limit')) {
            GenerateCatalogExport::dispatch($transfer->id);

            return to_route('catalog.history')->with('status', 'Your export has started. It will appear here when it\'s ready.');
        }

        GenerateCatalogExport::dispatchSync($transfer->id);

        return to_route('catalog.history')->with('status', 'Your export is ready to download.');
    }

    public function download(CatalogTransfer $transfer): StreamedResponse|RedirectResponse
    {
        Gate::authorize('view', $transfer);

        abort_if($transfer->isImport(), 404);

        if ($transfer->stored_path === null || ! Storage::disk('local')->exists($transfer->stored_path)) {
            return to_route('catalog.history')->withErrors(['download' => 'This export has expired. Please create a new one.']);
        }

        return Storage::disk('local')->download($transfer->stored_path, $transfer->original_name);
    }
}
