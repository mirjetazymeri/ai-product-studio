<?php

namespace App\Http\Resources\Api\V1;

use App\Enums\AiError;
use App\Enums\AiGenerationStatus;
use App\Models\AiGeneration;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Prompts and custom instructions are never part of this response.
 *
 * @mixin AiGeneration
 */
class GenerationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $error = AiError::tryFrom((string) $this->error_code);

        return [
            'generation_id' => $this->id,
            'product_id' => $this->product_id,
            'language' => $this->language->value,
            'status' => $this->status->apiValue(),
            'is_regeneration' => $this->is_regeneration,
            'content' => $this->status === AiGenerationStatus::Succeeded ? $this->generated_content : null,
            'review_status' => $this->reviewItem?->review_status,
            'usage' => $this->total_tokens === null ? null : [
                'input_tokens' => $this->input_tokens,
                'output_tokens' => $this->output_tokens,
                'total_tokens' => $this->total_tokens,
            ],
            'error' => $this->status === AiGenerationStatus::Failed && $error !== null ? [
                'code' => $error->publicCode(),
                'message' => $error->publicMessage(),
            ] : null,
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
