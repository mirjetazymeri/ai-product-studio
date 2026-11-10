<?php

namespace App\Http\Resources\Api\V1;

use App\Models\ProductContent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ProductContent
 */
class ProductContentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'product_id' => $this->product_id,
            'language' => $this->language->value,
            'title' => $this->title,
            'short_description' => $this->short_description,
            'long_description' => $this->long_description,
            'meta_title' => $this->meta_title,
            'meta_description' => $this->meta_description,
            'slug' => $this->slug,
            'keywords' => $this->keywords ?? [],
            'source' => $this->source->value,
            'approved' => $this->source->isApprovedForSync(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
