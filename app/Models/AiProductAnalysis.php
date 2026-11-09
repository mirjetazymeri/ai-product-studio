<?php

namespace App\Models;

use App\Enums\AiGenerationStatus;
use App\Models\Concerns\CountsTowardsAiQuota;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Usage record for one "Analyze with AI" request. The user's description is not stored.
 */
#[Fillable(['provider', 'model', 'status', 'input_tokens', 'output_tokens', 'total_tokens', 'error_code'])]
class AiProductAnalysis extends Model
{
    use CountsTowardsAiQuota;

    protected $table = 'ai_product_analyses';

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'status' => AiGenerationStatus::class,
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
