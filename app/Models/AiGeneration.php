<?php

namespace App\Models;

use App\Enums\AiError;
use App\Enums\AiGenerationStatus;
use App\Enums\ContentLanguage;
use App\Models\Concerns\CountsTowardsAiQuota;
use Database\Factories\AiGenerationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// user_id is deliberately not fillable: ownership is only set through $user->aiGenerations().
#[Fillable(['product_id', 'product_name', 'provider', 'model', 'language', 'status', 'is_regeneration', 'preferences', 'input_tokens', 'output_tokens', 'total_tokens', 'generated_content', 'error_code', 'saved_at'])]
class AiGeneration extends Model
{
    use CountsTowardsAiQuota;

    /** @use HasFactory<AiGenerationFactory> */
    use HasFactory;

    /** Fixed demo text that no AI model produced. */
    public const SAMPLE_PROVIDER = 'sample';

    /**
     * @param  Builder<AiGeneration>  $query
     */
    public function scopeReal(Builder $query): void
    {
        $query->where('provider', '!=', self::SAMPLE_PROVIDER);
    }

    public function isSample(): bool
    {
        return $this->provider === self::SAMPLE_PROVIDER;
    }

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'language' => ContentLanguage::class,
            'status' => AiGenerationStatus::class,
            'is_regeneration' => 'boolean',
            'preferences' => 'array',
            'generated_content' => 'array',
            'saved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function errorMessage(): ?string
    {
        return AiError::tryFrom((string) $this->error_code)?->message();
    }
}
