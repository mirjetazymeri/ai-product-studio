<?php

namespace App\Services\AI;

use App\Enums\AiError;
use App\Enums\AiGenerationStatus;
use App\Enums\ContentLanguage;
use App\Enums\ContentSource;
use App\Enums\UsageAction;
use App\Jobs\GenerateQueuedContent;
use App\Models\AiGeneration;
use App\Models\Product;
use App\Models\User;
use App\Services\AI\Exceptions\AiGenerationException;
use App\Services\AI\Prompts\ProductContentPrompt;
use App\Services\Billing\PlanEntitlementService;
use App\Services\Billing\UsageMeteringService;
use App\Services\ProductService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Throwable;

class ProductContentGenerator
{
    private const STALE_AFTER_MINUTES = 15;

    private const BUSY_RETRY_SECONDS = 15;

    public function __construct(
        private readonly GeminiService $gemini,
        private readonly ProductContentPrompt $prompt,
        private readonly GenerationQuota $quota,
        private readonly ProductService $products,
        private readonly ContentReviewQueue $reviewQueue,
        private readonly UsageMeteringService $usage,
        private readonly PlanEntitlementService $entitlements,
    ) {}

    /**
     * Generate a preview for review. Product content is never changed here.
     *
     * @throws AiGenerationException
     */
    public function generate(
        User $user,
        Product $product,
        ContentLanguage $language,
        bool $isRegeneration = false,
        GenerationPreferences $preferences = new GenerationPreferences,
        ?UsageAction $usageAction = null,
    ): AiGeneration {
        $this->ensureEntitled($user);
        $this->ensureProductHasEnoughData($product);
        $this->gemini->ensureConfigured();

        $lock = Cache::lock("ai-generation:user:{$user->id}", $this->gemini->maximumDurationInSeconds());

        if (! $lock->get()) {
            throw new AiGenerationException(AiError::DuplicateRequest);
        }

        try {
            $this->closeStaleGenerations($user);
            $this->quota->ensureAvailableFor($user);

            $generation = $this->startMetered($user, $product, $language, $isRegeneration, $preferences, AiGenerationStatus::Pending, $usageAction);

            return $this->run($generation, $product, $language, $preferences);
        } catch (QueryException $exception) {
            Log::error('AI generation could not be recorded.', ['user_id' => $user->id, 'product_id' => $product->id, 'sql_state' => $exception->getCode()]);

            throw new AiGenerationException(AiError::DatabaseError);
        } finally {
            $lock->release();
        }
    }

    /**
     * Accepts a generation to run on the queue. The row exists, and counts towards quota,
     * from this moment, so its ID is stable and limits can't be exceeded by a burst.
     *
     * @throws AiGenerationException
     */
    public function enqueue(
        User $user,
        Product $product,
        ContentLanguage $language,
        bool $isRegeneration = false,
        GenerationPreferences $preferences = new GenerationPreferences,
    ): AiGeneration {
        $this->ensureEntitled($user);
        $this->ensureProductHasEnoughData($product);
        $this->gemini->ensureConfigured();

        try {
            $generation = Cache::lock('ai-generation:accept', 10)->block(5, function () use ($user, $product, $language, $isRegeneration, $preferences) {
                $this->closeStaleGenerations($user);

                if ($this->activeGeneration($user, $product, $language) !== null) {
                    throw new AiGenerationException(AiError::DuplicateRequest);
                }

                $this->quota->ensureAvailableFor($user);

                return $this->startMetered($user, $product, $language, $isRegeneration, $preferences, AiGenerationStatus::Queued);
            });
        } catch (LockTimeoutException) {
            throw new AiGenerationException(AiError::DuplicateRequest);
        }

        try {
            GenerateQueuedContent::dispatch($generation->id, $preferences->customInstructions);
        } catch (Throwable $exception) {
            report($exception);

            $this->failActive($generation->id, AiError::Unexpected);

            throw new AiGenerationException(AiError::Unexpected);
        }

        return $generation->refresh();
    }

    public function activeGeneration(User $user, Product $product, ContentLanguage $language): ?AiGeneration
    {
        return $user->aiGenerations()
            ->where('product_id', $product->id)
            ->where('language', $language->value)
            ->whereIn('status', [AiGenerationStatus::Queued->value, AiGenerationStatus::Pending->value, AiGenerationStatus::Processing->value])
            ->latest('id')
            ->first();
    }

    /**
     * Runs one queued generation. The result waits in Content Review: saved product
     * content is never touched here.
     *
     * @return int|null seconds to wait before trying again, or null when finished
     */
    public function processQueued(int $generationId, ?string $customInstructions = null): ?int
    {
        $generation = AiGeneration::query()->real()->find($generationId);

        if ($generation === null || $generation->status !== AiGenerationStatus::Queued) {
            return null;
        }

        $product = $generation->product;

        if ($product === null) {
            $this->failActive($generation->id, AiError::ProductMissing);

            return null;
        }

        if ($generation->created_at->lt(now()->subHours((int) config('api.generation_queue_expiry_hours')))) {
            $this->failActive($generation->id, AiError::Interrupted);

            return null;
        }

        // The same per-minute counter as the web forms and bulk generation.
        $rateLimitKey = md5('ai-generate'.$generation->user_id);

        if (RateLimiter::tooManyAttempts($rateLimitKey, (int) config('services.gemini.per_minute_limit'))) {
            return RateLimiter::availableIn($rateLimitKey) + 1;
        }

        $lock = Cache::lock("ai-generation:user:{$generation->user_id}", $this->gemini->maximumDurationInSeconds());

        if (! $lock->get()) {
            return self::BUSY_RETRY_SECONDS;
        }

        try {
            // A retried job finds the row already claimed, so Gemini is only called once.
            $claimed = AiGeneration::query()
                ->whereKey($generation->id)
                ->where('status', AiGenerationStatus::Queued->value)
                ->update(['status' => AiGenerationStatus::Processing->value, 'updated_at' => now()]);

            if ($claimed !== 1) {
                return null;
            }

            RateLimiter::hit($rateLimitKey, 60);

            $generation->refresh();

            $this->run($generation, $product, $generation->language, GenerationPreferences::fromHistory($generation->preferences, $customInstructions));

            $this->reviewQueue->add($this->reviewQueue->createBatch($generation->user, $generation->preferences), $product, $generation);
        } catch (AiGenerationException) {
            // run() has already recorded the failure on the generation.
        } finally {
            $lock->release();
        }

        return null;
    }

    /**
     * Only a generation still in flight can fail. A finished result is left alone.
     */
    public function failActive(int $generationId, AiError $error): void
    {
        AiGeneration::query()
            ->whereKey($generationId)
            ->whereIn('status', [AiGenerationStatus::Queued->value, AiGenerationStatus::Pending->value, AiGenerationStatus::Processing->value])
            ->update(['status' => AiGenerationStatus::Failed->value, 'error_code' => $error->value]);

        $userId = AiGeneration::query()->whereKey($generationId)->value('user_id');

        if ($userId !== null) {
            $this->usage->release($userId, UsageMeteringService::generationKey($generationId));
        }
    }

    /**
     * Store reviewed content. Existing content is only replaced with explicit confirmation.
     *
     * @param  array<string, mixed>  $fields
     *
     * @throws AiGenerationException
     */
    public function save(AiGeneration $generation, array $fields, bool $confirmOverwrite): Product
    {
        try {
            return DB::transaction(function () use ($generation, $fields, $confirmOverwrite) {
                $locked = AiGeneration::query()->lockForUpdate()->findOrFail($generation->id);

                if ($locked->status !== AiGenerationStatus::Succeeded) {
                    throw new AiGenerationException(AiError::NotSavable);
                }

                $product = $locked->product ?? throw new AiGenerationException(AiError::ProductMissing);

                // A double-submitted form must not write twice.
                if ($locked->saved_at !== null) {
                    return $product;
                }

                $exists = $product->contents()->where('language', $locked->language->value)->exists();

                if ($exists && ! $confirmOverwrite) {
                    throw new AiGenerationException(AiError::ContentExists);
                }

                $this->products->saveContent($product, $locked->language, $fields, $locked->isSample() ? ContentSource::Sample : ContentSource::Ai);
                $locked->update(['saved_at' => now()]);

                Log::info('AI content saved.', ['generation_id' => $locked->id, 'product_id' => $product->id]);

                return $product;
            });
        } catch (QueryException $exception) {
            Log::error('AI content could not be saved.', ['generation_id' => $generation->id, 'sql_state' => $exception->getCode()]);

            throw new AiGenerationException(AiError::DatabaseError);
        }
    }

    public function hasEnoughData(Product $product): bool
    {
        return filled($product->original_name)
            && (filled($product->details) || filled($product->category) || filled($product->material));
    }

    private function ensureProductHasEnoughData(Product $product): void
    {
        if (! $this->hasEnoughData($product)) {
            throw new AiGenerationException(AiError::MissingProductData);
        }
    }

    /**
     * A process killed mid-request leaves a pending row, which would otherwise stay "in progress".
     */
    public function closeStaleGenerations(User $user): void
    {
        $cutoff = now()->subMinutes(self::STALE_AFTER_MINUTES);

        // A queued generation may start long after it was created, so it's timed from its claim.
        $user->aiGenerations()
            ->where(fn ($query) => $query
                ->where(fn ($pending) => $pending->where('status', AiGenerationStatus::Pending->value)->where('created_at', '<', $cutoff))
                ->orWhere(fn ($processing) => $processing->where('status', AiGenerationStatus::Processing->value)->where('updated_at', '<', $cutoff)))
            ->update(['status' => AiGenerationStatus::Failed->value, 'error_code' => AiError::Interrupted->value]);

        $this->usage->releaseForFailedGenerations($user);
    }

    /**
     * @throws AiGenerationException
     */
    private function ensureEntitled(User $user): void
    {
        if (! $this->entitlements->can($user, 'ai.generate')) {
            throw new AiGenerationException(AiError::PlanFeatureUnavailable);
        }
    }

    /**
     * In one transaction, so a request over the monthly allowance leaves no trace.
     */
    private function startMetered(User $user, Product $product, ContentLanguage $language, bool $isRegeneration, GenerationPreferences $preferences, AiGenerationStatus $status, ?UsageAction $usageAction = null): AiGeneration
    {
        return DB::transaction(function () use ($user, $product, $language, $isRegeneration, $preferences, $status, $usageAction) {
            // Before the insert, whose foreign key check would otherwise deadlock with this lock.
            $this->usage->lockUser($user->id);

            $generation = $this->start($user, $product, $language, $isRegeneration, $preferences, $status);

            $this->usage->reserve(
                $user,
                $usageAction ?? ($isRegeneration ? UsageAction::Regeneration : UsageAction::Generation),
                UsageMeteringService::generationKey($generation->id),
                'generation',
                $generation->id,
            );

            return $generation;
        });
    }

    private function start(User $user, Product $product, ContentLanguage $language, bool $isRegeneration, GenerationPreferences $preferences, AiGenerationStatus $status = AiGenerationStatus::Pending): AiGeneration
    {
        return $user->aiGenerations()->create([
            'product_id' => $product->id,
            'product_name' => $product->original_name,
            'provider' => GeminiService::PROVIDER,
            'model' => $this->gemini->model(),
            'language' => $language,
            'status' => $status,
            'is_regeneration' => $isRegeneration,
            'preferences' => $preferences->toHistory(),
        ]);
    }

    private function run(AiGeneration $generation, Product $product, ContentLanguage $language, GenerationPreferences $preferences): AiGeneration
    {
        try {
            $result = $this->gemini->generateJson(
                $this->prompt->systemInstruction($language, $preferences),
                $this->prompt->userPrompt($product, $language, $preferences),
                $this->prompt->schema(),
            );

            $content = $this->validated($result->data, $language, $result->usage);
        } catch (AiGenerationException $exception) {
            $generation->update([
                'status' => AiGenerationStatus::Failed,
                'error_code' => $exception->error->value,
                ...($exception->usage ?? new TokenUsage)->toColumns(),
            ]);

            // A failed request produced nothing, so it doesn't use the monthly allowance.
            $this->usage->release($generation->user_id, UsageMeteringService::generationKey($generation->id));

            throw $exception;
        }

        $generation->update([
            'status' => AiGenerationStatus::Succeeded,
            'generated_content' => $content->toArray(),
            ...$result->usage->toColumns(),
        ]);

        $this->usage->consume($generation->user_id, UsageMeteringService::generationKey($generation->id));

        return $generation;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function validated(array $data, ContentLanguage $language, TokenUsage $usage): GeneratedContent
    {
        $validator = Validator::make(ProductContentRules::normalise($data, $language), ProductContentRules::rules());

        if ($validator->fails()) {
            // Field names only: the rejected text itself stays out of the logs.
            Log::warning('Gemini content failed validation.', ['fields' => $validator->errors()->keys()]);

            throw new AiGenerationException(AiError::InvalidResponse, $usage);
        }

        return GeneratedContent::fromArray($validator->validated());
    }
}
