<?php

namespace App\Services\AI;

use App\Enums\AiError;
use App\Models\AiGeneration;
use App\Models\AiProductAnalysis;
use App\Models\User;
use App\Services\AI\Exceptions\AiGenerationException;

class GenerationQuota
{
    public function remainingFor(User $user): int
    {
        // Generations and product analyses use the same Gemini project, so they share one allowance.
        $userRemaining = (int) config('services.gemini.user_daily_limit')
            - $user->aiGenerations()->countingTowardsQuotaToday()->count()
            - $user->aiProductAnalyses()->countingTowardsQuotaToday()->count();

        $appRemaining = (int) config('services.gemini.app_daily_limit')
            - AiGeneration::query()->real()->countingTowardsQuotaToday()->count()
            - AiProductAnalysis::query()->countingTowardsQuotaToday()->count();

        return max(0, min($userRemaining, $appRemaining));
    }

    /**
     * @throws AiGenerationException
     */
    public function ensureAvailableFor(User $user): void
    {
        if ($this->remainingFor($user) < 1) {
            throw new AiGenerationException(AiError::DailyLimitReached);
        }
    }
}
