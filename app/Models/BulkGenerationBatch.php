<?php

namespace App\Models;

use App\Services\AI\GenerationPreferences;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['submission_token', 'status', 'preferences', 'custom_instructions'])]
class BulkGenerationBatch extends Model
{
    public const RUNNING = 'running';

    public const COMPLETED = 'completed';

    public const COMPLETED_WITH_FAILURES = 'completed_with_failures';

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'preferences' => 'array',
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
     * @return HasMany<BulkGenerationItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(BulkGenerationItem::class);
    }

    public function generationPreferences(): GenerationPreferences
    {
        return GenerationPreferences::fromHistory($this->preferences, $this->custom_instructions);
    }
}
