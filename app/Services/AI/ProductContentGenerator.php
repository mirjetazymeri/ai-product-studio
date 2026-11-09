<?php

namespace App\Services\AI;

use App\Enums\AiError;
use App\Enums\AiGenerationStatus;
use App\Enums\ContentLanguage;
use App\Enums\ContentSource;
use App\Models\AiGeneration;
use App\Models\Product;
use App\Models\User;
use App\Services\AI\Exceptions\AiGenerationException;
use App\Services\AI\Prompts\ProductContentPrompt;
use App\Services\ProductService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class ProductContentGenerator
{
    private const STALE_AFTER_MINUTES = 15;

    public function __construct(
        private readonly GeminiService $gemini,
        private readonly ProductContentPrompt $prompt,
        private readonly GenerationQuota $quota,
        private readonly ProductService $products,
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
    ): AiGeneration {
        $this->ensureProductHasEnoughData($product);
        $this->gemini->ensureConfigured();

        $lock = Cache::lock("ai-generation:user:{$user->id}", $this->gemini->maximumDurationInSeconds());

        if (! $lock->get()) {
            throw new AiGenerationException(AiError::DuplicateRequest);
        }

        try {
            $this->closeStaleGenerations($user);
            $this->quota->ensureAvailableFor($user);

            $generation = $this->start($user, $product, $language, $isRegeneration, $preferences);

            return $this->run($generation, $product, $language, $preferences);
        } catch (QueryException $exception) {
            Log::error('AI generation could not be recorded.', ['user_id' => $user->id, 'product_id' => $product->id, 'sql_state' => $exception->getCode()]);

            throw new AiGenerationException(AiError::DatabaseError);
        } finally {
            $lock->release();
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
    private function closeStaleGenerations(User $user): void
    {
        $user->aiGenerations()
            ->where('status', AiGenerationStatus::Pending->value)
            ->where('created_at', '<', now()->subMinutes(self::STALE_AFTER_MINUTES))
            ->update(['status' => AiGenerationStatus::Failed->value, 'error_code' => AiError::Interrupted->value]);
    }

    private function start(User $user, Product $product, ContentLanguage $language, bool $isRegeneration, GenerationPreferences $preferences): AiGeneration
    {
        return $user->aiGenerations()->create([
            'product_id' => $product->id,
            'product_name' => $product->original_name,
            'provider' => GeminiService::PROVIDER,
            'model' => $this->gemini->model(),
            'language' => $language,
            'status' => AiGenerationStatus::Pending,
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

            throw $exception;
        }

        $generation->update([
            'status' => AiGenerationStatus::Succeeded,
            'generated_content' => $content->toArray(),
            ...$result->usage->toColumns(),
        ]);

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
