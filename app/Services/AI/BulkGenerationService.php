<?php

namespace App\Services\AI;

use App\Enums\AiError;
use App\Enums\BulkItemStatus;
use App\Enums\UsageAction;
use App\Jobs\RunBulkGeneration;
use App\Models\BulkGenerationBatch;
use App\Models\BulkGenerationItem;
use App\Models\Product;
use App\Models\User;
use App\Services\AI\Exceptions\AiGenerationException;
use App\Services\Billing\Exceptions\PlanRestrictionException;
use App\Services\Billing\PlanEntitlementService;
use App\Services\Billing\UsageMeteringService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Runs many generations through the same ProductContentGenerator as single requests,
 * one at a time. Nothing here saves content: every result waits for review.
 */
class BulkGenerationService
{
    private const STALE_PROCESSING_MINUTES = 15;

    private const LOCK_BUSY_DELAY_SECONDS = 15;

    private const QUOTA_RETRY_SECONDS = 60;

    private const MAX_QUOTA_ATTEMPTS = 3;

    /** Failures that would repeat for every remaining item, so the batch stops. */
    private const FATAL_ERRORS = [AiError::MissingApiKey, AiError::MissingModel, AiError::InvalidApiKey, AiError::ModelUnavailable];

    public function __construct(
        private readonly ProductContentGenerator $generator,
        private readonly GenerationQuota $quota,
        private readonly UsageMeteringService $usage,
        private readonly PlanEntitlementService $entitlements,
    ) {}

    public function estimateRequests(int $products, int $languages): int
    {
        return $products * $languages;
    }

    /**
     * @param  list<int>  $productIds
     * @param  list<string>  $languages
     *
     * @throws AiGenerationException when the batch would exceed today's allowance
     */
    public function start(User $user, array $productIds, array $languages, GenerationPreferences $preferences, string $token): BulkGenerationBatch
    {
        $existing = $user->bulkGenerationBatches()->where('submission_token', $token)->first();

        if ($existing !== null) {
            return $existing;
        }

        $this->entitlements->ensure($user, 'ai.bulk');

        $products = $this->eligibleProducts($user, $productIds);
        $requests = $this->estimateRequests($products->count(), count($languages));
        $batchLimit = $this->entitlements->limit($user, 'bulk_items_per_batch');

        if ($requests > $batchLimit) {
            throw new PlanRestrictionException("Your plan allows {$batchLimit} items per bulk batch, and this batch has {$requests}. Choose fewer products or languages, or upgrade.", 'plan_limit_reached', 'ai.bulk', $this->entitlements->plan($user)->key);
        }

        if ($requests > $this->usage->remaining($user)) {
            throw new AiGenerationException(AiError::MonthlyLimitReached);
        }

        if ($requests > $this->quota->remainingFor($user)) {
            throw new AiGenerationException(AiError::DailyLimitReached);
        }

        $batch = DB::transaction(function () use ($user, $products, $languages, $preferences, $token) {
            $batch = $user->bulkGenerationBatches()->create([
                'submission_token' => $token,
                'status' => BulkGenerationBatch::RUNNING,
                'preferences' => $preferences->toHistory(),
                'custom_instructions' => $preferences->customInstructions,
            ]);

            foreach ($products as $product) {
                foreach ($languages as $language) {
                    $batch->items()->create([
                        'user_id' => $user->id,
                        'product_id' => $product->id,
                        'language' => $language,
                        'status' => BulkItemStatus::Queued,
                    ]);
                }
            }

            return $batch;
        });

        RunBulkGeneration::dispatch($batch->id);

        return $batch;
    }

    /**
     * @param  list<int>  $productIds
     * @return Collection<int, Product>
     */
    public function eligibleProducts(User $user, array $productIds): Collection
    {
        return $user->products()
            ->whereIn('id', $productIds)
            ->get()
            ->filter(fn (Product $product) => $this->generator->hasEnoughData($product))
            ->values();
    }

    /**
     * Handle the next queued item.
     *
     * @return int|null seconds until the next item may run, or null when the batch is finished
     */
    public function processNext(int $batchId): ?int
    {
        $batch = BulkGenerationBatch::query()->with('user')->find($batchId);

        if ($batch === null || $batch->status !== BulkGenerationBatch::RUNNING) {
            return null;
        }

        $this->requeueStaleItems($batch);

        // Checked for every item, so a plan that lapses mid-batch stops the remaining work.
        if (! $this->entitlements->can($batch->user, 'ai.bulk')) {
            $batch->items()->where('status', BulkItemStatus::Queued->value)->update(['status' => BulkItemStatus::Failed->value, 'error_code' => AiError::PlanFeatureUnavailable->value]);
            $this->finish($batch);

            return null;
        }

        if (! $batch->items()->where('status', BulkItemStatus::Queued->value)->exists()) {
            $this->finish($batch);

            return null;
        }

        $rateLimitKey = $this->rateLimitKey($batch->user);
        $perMinute = (int) config('services.gemini.per_minute_limit');

        if (RateLimiter::tooManyAttempts($rateLimitKey, $perMinute)) {
            return RateLimiter::availableIn($rateLimitKey) + 1;
        }

        $item = $this->claimNextItem($batch);

        if ($item === null) {
            return 1;
        }

        RateLimiter::hit($rateLimitKey, 60);

        return $this->generate($batch, $item);
    }

    /**
     * Failed items only, so work that already succeeded is never repeated.
     *
     * @return array{retried: int, remaining: int}
     */
    public function retryFailed(BulkGenerationBatch $batch): array
    {
        if ($batch->status === BulkGenerationBatch::RUNNING) {
            return ['retried' => 0, 'remaining' => 0];
        }

        $failed = $batch->items()->where('status', BulkItemStatus::Failed->value)->orderBy('id')->get();
        $allowance = $this->quota->remainingFor($batch->user);
        $toRetry = $failed->take($allowance);

        if ($toRetry->isEmpty()) {
            return ['retried' => 0, 'remaining' => $failed->count()];
        }

        DB::transaction(function () use ($batch, $toRetry) {
            BulkGenerationItem::query()->whereKey($toRetry->modelKeys())->update([
                'status' => BulkItemStatus::Queued->value,
                'error_code' => null,
                'attempts' => 0,
            ]);

            $batch->update(['status' => BulkGenerationBatch::RUNNING]);
        });

        RunBulkGeneration::dispatch($batch->id);

        return ['retried' => $toRetry->count(), 'remaining' => $failed->count() - $toRetry->count()];
    }

    private function rateLimitKey(User $user): string
    {
        // The same counter the throttle:ai-generate route middleware uses.
        return md5('ai-generate'.$user->id);
    }

    private function requeueStaleItems(BulkGenerationBatch $batch): void
    {
        $batch->items()
            ->where('status', BulkItemStatus::Processing->value)
            ->where('updated_at', '<', now()->subMinutes(self::STALE_PROCESSING_MINUTES))
            ->update(['status' => BulkItemStatus::Queued->value]);
    }

    /**
     * Claimed with a conditional update, so two workers can't take the same item.
     */
    private function claimNextItem(BulkGenerationBatch $batch): ?BulkGenerationItem
    {
        $item = $batch->items()->where('status', BulkItemStatus::Queued->value)->orderBy('id')->first();

        if ($item === null) {
            return null;
        }

        $claimed = BulkGenerationItem::query()
            ->whereKey($item->id)
            ->where('status', BulkItemStatus::Queued->value)
            ->update(['status' => BulkItemStatus::Processing->value, 'attempts' => $item->attempts + 1, 'updated_at' => now()]);

        return $claimed === 1 ? $item->refresh() : null;
    }

    private function generate(BulkGenerationBatch $batch, BulkGenerationItem $item): ?int
    {
        try {
            $generation = $this->generator->generate(
                $batch->user,
                $item->product,
                $item->language,
                false,
                $batch->generationPreferences(),
                UsageAction::BulkItem,
            );
        } catch (AiGenerationException $exception) {
            return $this->handleFailure($batch, $item, $exception);
        }

        $item->update([
            'status' => BulkItemStatus::Generated,
            'review_status' => BulkGenerationItem::REVIEW_PENDING,
            'ai_generation_id' => $generation->id,
            'error_code' => null,
        ]);

        return 0;
    }

    private function handleFailure(BulkGenerationBatch $batch, BulkGenerationItem $item, AiGenerationException $exception): ?int
    {
        $error = $exception->error;

        if ($error === AiError::DuplicateRequest) {
            $item->update(['status' => BulkItemStatus::Queued, 'attempts' => max(0, $item->attempts - 1)]);

            return self::LOCK_BUSY_DELAY_SECONDS;
        }

        if ($error === AiError::QuotaExceeded && $item->attempts < self::MAX_QUOTA_ATTEMPTS) {
            $item->update(['status' => BulkItemStatus::Queued]);

            return $exception->retryAfter ?? self::QUOTA_RETRY_SECONDS;
        }

        if ($error === AiError::DailyLimitReached || $error === AiError::MonthlyLimitReached || $error === AiError::QuotaExceeded || in_array($error, self::FATAL_ERRORS, true)) {
            $this->failRemaining($batch, $item, $error);
            $this->finish($batch);

            return null;
        }

        $item->update(['status' => BulkItemStatus::Failed, 'error_code' => $error->value]);

        return 0;
    }

    private function failRemaining(BulkGenerationBatch $batch, BulkGenerationItem $item, AiError $error): void
    {
        $item->update(['status' => BulkItemStatus::Failed, 'error_code' => $error->value]);

        $batch->items()
            ->where('status', BulkItemStatus::Queued->value)
            ->update(['status' => BulkItemStatus::Failed->value, 'error_code' => $error->value]);
    }

    private function finish(BulkGenerationBatch $batch): void
    {
        $hasFailures = $batch->items()->where('status', BulkItemStatus::Failed->value)->exists();

        $batch->update(['status' => $hasFailures ? BulkGenerationBatch::COMPLETED_WITH_FAILURES : BulkGenerationBatch::COMPLETED]);
    }
}
