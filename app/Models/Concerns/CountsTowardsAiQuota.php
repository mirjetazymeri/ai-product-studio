<?php

namespace App\Models\Concerns;

use App\Enums\AiError;
use App\Enums\AiGenerationStatus;
use Illuminate\Database\Eloquent\Builder;

trait CountsTowardsAiQuota
{
    /**
     * @param  Builder<static>  $query
     */
    public function scopeCountingTowardsQuotaToday(Builder $query): void
    {
        $query->where('created_at', '>=', today())
            ->where(function (Builder $query) {
                $query->where('status', '!=', AiGenerationStatus::Failed->value)
                    ->orWhereNull('error_code')
                    ->orWhereNotIn('error_code', AiError::notCountedTowardsQuota());
            });
    }
}
