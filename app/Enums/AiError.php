<?php

namespace App\Enums;

/**
 * Every way AI generation can fail, with text that is safe to show to users.
 */
enum AiError: string
{
    case MissingApiKey = 'missing_api_key';
    case MissingModel = 'missing_model';
    case InvalidApiKey = 'invalid_api_key';
    case ModelUnavailable = 'model_unavailable';
    case QuotaExceeded = 'quota_exceeded';
    case Timeout = 'timeout';
    case NetworkError = 'network_error';
    case ProviderUnavailable = 'provider_unavailable';
    case ProviderError = 'provider_error';
    case InvalidResponse = 'invalid_response';
    case EmptyResponse = 'empty_response';
    case BlockedResponse = 'blocked_response';
    case MissingProductData = 'missing_product_data';
    case UnclearProductDescription = 'unclear_product_description';
    case ProductMissing = 'product_missing';
    case NotSavable = 'not_savable';
    case ContentExists = 'content_exists';
    case DuplicateRequest = 'duplicate_request';
    case RateLimited = 'rate_limited';
    case DailyLimitReached = 'daily_limit_reached';
    case MonthlyLimitReached = 'monthly_limit_reached';
    case PlanFeatureUnavailable = 'plan_feature_unavailable';
    case DatabaseError = 'database_error';
    case Interrupted = 'interrupted';
    case Unexpected = 'unexpected';

    public function message(): string
    {
        return match ($this) {
            self::MissingApiKey => 'AI generation isn\'t set up yet: GEMINI_API_KEY is missing from the server configuration.',
            self::MissingModel => 'AI generation isn\'t set up yet: GEMINI_MODEL is missing from the server configuration.',
            self::InvalidApiKey => 'Gemini rejected the configured API key, or its project doesn\'t have access. Check GEMINI_API_KEY and your Google AI Studio project.',
            self::ModelUnavailable => 'The model in GEMINI_MODEL isn\'t available for this API key. Update the configuration to a model your project can use.',
            self::QuotaExceeded => 'The Gemini quota has been reached. Please wait and try again later.',
            self::Timeout => 'Gemini took too long to respond. Please try again.',
            self::NetworkError => 'We couldn\'t reach Gemini. Check your connection and try again.',
            self::ProviderUnavailable => 'Gemini is temporarily unavailable. Please try again shortly.',
            self::ProviderError => 'Gemini couldn\'t process this request. Please try again.',
            self::InvalidResponse => 'Gemini returned content we couldn\'t use, so nothing was kept. Please try again.',
            self::EmptyResponse => 'Gemini returned an empty response. Please try again.',
            self::BlockedResponse => 'Gemini declined to generate content for this product. Try adjusting the product details.',
            self::MissingProductData => 'Add a category, material or product details first, so the content can be based on real information.',
            self::UnclearProductDescription => 'We couldn\'t identify a product in that description. Try saying what the product is and what it\'s for.',
            self::ProductMissing => 'This product no longer exists, so the content can\'t be saved.',
            self::NotSavable => 'This generation has no content to save.',
            self::ContentExists => 'Content already exists for this language. Confirm that you want to replace it.',
            self::DuplicateRequest => 'A generation is already running. Please wait for it to finish.',
            self::RateLimited => 'You\'re generating too quickly. Please wait a minute and try again.',
            self::DailyLimitReached => 'The daily AI generation limit has been reached. Please try again tomorrow.',
            self::MonthlyLimitReached => 'You\'ve used this month\'s AI allowance on your plan. Upgrade, or wait until it resets next month.',
            self::PlanFeatureUnavailable => 'This AI feature isn\'t included in your current plan.',
            self::DatabaseError => 'We couldn\'t save this to the database. Please try again.',
            self::Interrupted => 'This generation was interrupted before it finished.',
            self::Unexpected => 'Something went wrong. Please try again.',
        };
    }

    /**
     * For outside API clients, who shouldn't learn how this server is configured.
     */
    public function publicMessage(): string
    {
        return $this->isConfigurationProblem() ? 'AI generation is temporarily unavailable.' : $this->message();
    }

    public function publicCode(): string
    {
        return $this->isConfigurationProblem() ? 'ai_unavailable' : $this->value;
    }

    private function isConfigurationProblem(): bool
    {
        return in_array($this, [self::MissingApiKey, self::MissingModel, self::InvalidApiKey, self::ModelUnavailable], true);
    }

    public function httpStatus(): int
    {
        return match ($this) {
            self::MissingProductData, self::UnclearProductDescription => 422,
            self::ProductMissing, self::NotSavable => 410,
            self::ContentExists, self::DuplicateRequest => 409,
            self::QuotaExceeded, self::RateLimited, self::DailyLimitReached, self::MonthlyLimitReached => 429,
            self::PlanFeatureUnavailable => 403,
            self::Timeout => 504,
            self::NetworkError, self::InvalidResponse, self::EmptyResponse, self::BlockedResponse, self::ProviderError => 502,
            self::MissingApiKey, self::MissingModel, self::InvalidApiKey, self::ModelUnavailable, self::ProviderUnavailable => 503,
            self::DatabaseError, self::Interrupted, self::Unexpected => 500,
        };
    }

    /**
     * Rejected before doing useful work, so these attempts don't use up the daily limit.
     *
     * @return list<string>
     */
    public static function notCountedTowardsQuota(): array
    {
        return [self::InvalidApiKey->value, self::ModelUnavailable->value, self::QuotaExceeded->value];
    }
}
