<?php

namespace App\Services\AI;

use App\Enums\BulkItemStatus;
use App\Models\AiGeneration;
use App\Models\BulkGenerationBatch;
use App\Models\BulkGenerationItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Puts finished drafts in Content Review, where a person decides what happens to them.
 */
class ContentReviewQueue
{
    public function createBatch(User $user, ?array $preferences = null): BulkGenerationBatch
    {
        return $user->bulkGenerationBatches()->create([
            'submission_token' => (string) Str::uuid(),
            'status' => BulkGenerationBatch::COMPLETED,
            'preferences' => $preferences ?? (new GenerationPreferences)->toHistory(),
        ]);
    }

    public function add(BulkGenerationBatch $batch, Product $product, AiGeneration $generation): BulkGenerationItem
    {
        return $batch->items()->create([
            'user_id' => $batch->user_id,
            'product_id' => $product->id,
            'ai_generation_id' => $generation->id,
            'language' => $generation->language,
            'status' => BulkItemStatus::Generated,
            'review_status' => BulkGenerationItem::REVIEW_PENDING,
        ]);
    }
}
