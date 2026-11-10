<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Product
 */
class ProductResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->original_name,
            'sku' => $this->sku,
            'category' => $this->category,
            'material' => $this->material,
            'details' => $this->details,
            'status' => $this->status->value,
            'languages' => $this->contents->map(fn ($content) => $content->language->value)->sort()->values(),
            'external_references' => $this->externalReferences->map(fn ($reference) => [
                'source' => $reference->source,
                'external_id' => $reference->external_id,
            ])->values(),
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
