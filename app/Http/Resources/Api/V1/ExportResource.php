<?php

namespace App\Http\Resources\Api\V1;

use App\Enums\CatalogTransferStatus;
use App\Models\CatalogTransfer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The stored file's location stays on the server.
 *
 * @mixin CatalogTransfer
 */
class ExportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $canDownload = $this->status === CatalogTransferStatus::Completed
            && $this->stored_path !== null
            && $this->expires_at->isFuture();

        return [
            'export_id' => $this->id,
            'status' => $this->status->value,
            'format' => $this->file_type,
            'total_records' => $this->total_records,
            'successful_records' => $this->successful_records,
            'approved_only' => (bool) ($this->options['approved_only'] ?? false),
            'languages' => $this->options['languages'] ?? [],
            'file_name' => $canDownload ? $this->original_name : null,
            'download_url' => $canDownload ? route('api.v1.exports.download', $this->id) : null,
            'error' => $this->status === CatalogTransferStatus::Failed ? $this->error_summary : null,
            'created_at' => $this->created_at->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
        ];
    }
}
