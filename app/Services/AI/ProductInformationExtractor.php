<?php

namespace App\Services\AI;

use App\Enums\AiError;
use App\Enums\AiGenerationStatus;
use App\Enums\ProductStatus;
use App\Enums\UsageAction;
use App\Models\User;
use App\Services\AI\Exceptions\AiGenerationException;
use App\Services\AI\Prompts\ProductInformationPrompt;
use App\Services\Billing\PlanEntitlementService;
use App\Services\Billing\UsageMeteringService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class ProductInformationExtractor
{
    private const OPTIONAL_FIELDS = ['sku', 'category', 'material', 'details'];

    private const EMPTY_PLACEHOLDERS = ['null', 'none', 'n/a', 'na', 'unknown', 'not specified', 'not provided', 'not mentioned'];

    public function __construct(
        private readonly GeminiService $gemini,
        private readonly ProductInformationPrompt $prompt,
        private readonly GenerationQuota $quota,
        private readonly UsageMeteringService $usage,
        private readonly PlanEntitlementService $entitlements,
    ) {}

    /**
     * Read a natural-language description into editable product fields. Nothing is created here.
     *
     * @return array{original_name: string, sku: ?string, category: ?string, material: ?string, details: ?string, status: string}
     *
     * @throws AiGenerationException
     */
    public function extract(User $user, string $description): array
    {
        if (! $this->entitlements->can($user, 'ai.analyze')) {
            throw new AiGenerationException(AiError::PlanFeatureUnavailable);
        }

        $this->gemini->ensureConfigured();

        // The same lock as content generation: one AI request at a time per user.
        $lock = Cache::lock("ai-generation:user:{$user->id}", $this->gemini->maximumDurationInSeconds());

        if (! $lock->get()) {
            throw new AiGenerationException(AiError::DuplicateRequest);
        }

        try {
            $this->quota->ensureAvailableFor($user);

            $usageKey = 'analysis:'.Str::uuid();
            $this->usage->reserve($user, UsageAction::Analysis, $usageKey, 'analysis');

            try {
                $product = $this->analyse($user, $description);
            } catch (\Throwable $exception) {
                $this->usage->release($user->id, $usageKey);

                throw $exception;
            }

            $this->usage->consume($user->id, $usageKey);

            return $product;
        } catch (QueryException $exception) {
            Log::error('Product analysis could not be recorded.', ['user_id' => $user->id, 'sql_state' => $exception->getCode()]);

            throw new AiGenerationException(AiError::DatabaseError);
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array{original_name: string, sku: ?string, category: ?string, material: ?string, details: ?string, status: string}
     */
    private function analyse(User $user, string $description): array
    {
        try {
            $result = $this->gemini->generateJson(
                $this->prompt->systemInstruction(),
                $this->prompt->userPrompt($description, $this->existingCategories($user)),
                $this->prompt->schema(),
            );

            $product = $this->validated($result->data, $description, $result->usage);
        } catch (AiGenerationException $exception) {
            $this->record($user, AiGenerationStatus::Failed, $exception->usage ?? new TokenUsage, $exception->error);

            throw $exception;
        }

        $this->record($user, AiGenerationStatus::Succeeded, $result->usage);

        return $product;
    }

    /**
     * @return list<string>
     */
    private function existingCategories(User $user): array
    {
        return $user->products()
            ->whereNotNull('category')
            ->distinct()
            ->orderBy('category')
            ->limit(100)
            ->pluck('category')
            ->all();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{original_name: string, sku: ?string, category: ?string, material: ?string, details: ?string, status: string}
     */
    private function validated(array $data, string $description, TokenUsage $usage): array
    {
        if (($data['is_product_description'] ?? null) === false) {
            throw new AiGenerationException(AiError::UnclearProductDescription, $usage);
        }

        $fields = $this->normalise($data, $description);

        if ($fields['original_name'] === null) {
            throw new AiGenerationException(AiError::UnclearProductDescription, $usage);
        }

        // The limits match the products columns.
        $validator = Validator::make($fields, [
            'original_name' => ['required', 'string', 'max:255'],
            'sku' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'string', 'max:120'],
            'material' => ['nullable', 'string', 'max:120'],
            'details' => ['nullable', 'string', 'max:10000'],
        ]);

        if ($validator->fails()) {
            // Field names only: the rejected text itself stays out of the logs.
            Log::warning('Product analysis failed validation.', ['fields' => $validator->errors()->keys()]);

            throw new AiGenerationException(AiError::InvalidResponse, $usage);
        }

        return [...$validator->validated(), 'status' => ProductStatus::Draft->value];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalise(array $data, string $description): array
    {
        $fields = ['original_name' => $this->clean($data['original_name'] ?? null)];

        foreach (self::OPTIONAL_FIELDS as $field) {
            $fields[$field] = $this->clean($data[$field] ?? null);
        }

        // These two must come from the user's own words, whatever the model says.
        if (is_string($fields['sku']) && ! Str::contains($description, $fields['sku'], ignoreCase: true)) {
            $fields['sku'] = null;
        }

        if (is_string($fields['material']) && ! $this->isMaterialMentioned($fields['material'], $description)) {
            $fields['material'] = null;
        }

        return $fields;
    }

    private function clean(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $value = trim($value);

        return $value === '' || in_array(mb_strtolower($value), self::EMPTY_PLACEHOLDERS, true) ? null : $value;
    }

    private function isMaterialMentioned(string $material, string $description): bool
    {
        $parts = preg_split('/\s*(?:,|\/|&|\+|\band\b)\s*/iu', $material, flags: PREG_SPLIT_NO_EMPTY);

        return collect($parts)->every(fn (string $part) => Str::contains($description, trim($part), ignoreCase: true));
    }

    private function record(User $user, AiGenerationStatus $status, TokenUsage $usage, ?AiError $error = null): void
    {
        $user->aiProductAnalyses()->create([
            'provider' => GeminiService::PROVIDER,
            'model' => $this->gemini->model(),
            'status' => $status,
            'error_code' => $error?->value,
            ...$usage->toColumns(),
        ]);
    }
}
