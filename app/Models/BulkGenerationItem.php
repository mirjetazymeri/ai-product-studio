<?php

namespace App\Models;

use App\Enums\AiError;
use App\Enums\BulkItemStatus;
use App\Enums\ContentLanguage;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'product_id', 'ai_generation_id', 'language', 'status', 'review_status', 'error_code', 'attempts', 'edited_content', 'reviewed_at'])]
class BulkGenerationItem extends Model
{
    public const REVIEW_PENDING = 'pending';

    public const REVIEW_APPROVED = 'approved';

    public const REVIEW_REJECTED = 'rejected';

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'language' => ContentLanguage::class,
            'status' => BulkItemStatus::class,
            'edited_content' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<AiGeneration, $this>
     */
    public function generation(): BelongsTo
    {
        return $this->belongsTo(AiGeneration::class, 'ai_generation_id');
    }

    /**
     * @return BelongsTo<BulkGenerationBatch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(BulkGenerationBatch::class, 'bulk_generation_batch_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function reviewableContent(): array
    {
        return $this->edited_content ?? $this->generation?->generated_content ?? [];
    }

    public function errorMessage(): ?string
    {
        return AiError::tryFrom((string) $this->error_code)?->message();
    }
}
