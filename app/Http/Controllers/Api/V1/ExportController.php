<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\CatalogTransferStatus;
use App\Enums\ContentLanguage;
use App\Enums\ExportContentScope;
use App\Exceptions\ApiException;
use App\Http\Requests\Api\V1\CreateExportRequest;
use App\Http\Resources\Api\V1\ExportResource;
use App\Jobs\GenerateCatalogExport;
use App\Models\CatalogTransfer;
use App\Services\Catalog\CatalogExporter;
use App\Services\Catalog\ProductSelection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportController extends ApiController
{
    public function store(CreateExportRequest $request, CatalogExporter $exporter, ProductSelection $selection): JsonResponse
    {
        $input = $request->selection();

        if ($selection->query($request->user(), $input)->doesntExist()) {
            throw new ApiException(422, 'no_products', 'There are no matching products to export.');
        }

        // Never the draft-including scopes: this endpoint only ever exports saved content.
        $transfer = $exporter->createTransfer(
            $request->user(),
            $request->validated('format'),
            ExportContentScope::Approved,
            $request->validated('languages') ?? ContentLanguage::values(),
            $input,
            $request->approvedOnly(),
        );

        GenerateCatalogExport::dispatch($transfer->id);

        return response()->json(['data' => [
            'export_id' => $transfer->id,
            'status' => $transfer->refresh()->status->value,
            'format' => $transfer->file_type,
        ]], 202)->header('Location', route('api.v1.exports.show', $transfer));
    }

    public function show(Request $request, string $export): ExportResource
    {
        return new ExportResource($this->ownedExport($request, $export));
    }

    public function download(Request $request, string $export): StreamedResponse
    {
        $transfer = $this->ownedExport($request, $export);

        if ($transfer->status === CatalogTransferStatus::Failed) {
            throw new ApiException(409, 'export_failed', 'This export failed. Please create a new one.');
        }

        if ($transfer->status !== CatalogTransferStatus::Completed) {
            throw new ApiException(409, 'export_not_ready', 'This export isn\'t ready yet.', ['retry_after' => 5], ['Retry-After' => '5']);
        }

        $disk = Storage::disk('local');

        if ($transfer->stored_path === null || $transfer->expires_at->isPast() || ! $disk->exists($transfer->stored_path)) {
            throw new ApiException(410, 'export_expired', 'This export has expired. Please create a new one.');
        }

        return $disk->download($transfer->stored_path, $transfer->original_name);
    }

    private function ownedExport(Request $request, string $id): CatalogTransfer
    {
        $transfer = $request->user()->catalogTransfers()->where('operation', CatalogTransfer::EXPORT)->findOrFail($id);

        Gate::authorize('view', $transfer);

        return $transfer;
    }
}
