<?php

return [

    // Set API_REQUIRE_HTTPS=false only behind a proxy that isn't yet trusted by Laravel.
    'require_https' => (bool) env('API_REQUIRE_HTTPS', env('APP_ENV') === 'production'),

    'pagination' => [
        'default' => (int) env('API_PAGE_SIZE', 15),
        'max' => (int) env('API_MAX_PAGE_SIZE', 100),
    ],

    // Requests per minute. These sit in front of the Gemini limits in services.gemini,
    // which still apply to every generation and analysis.
    'rate_limits' => [
        'general' => (int) env('API_RATE_LIMIT', 120),
        'generate' => (int) env('API_RATE_LIMIT_GENERATE', 20),
        'analyze' => (int) env('API_RATE_LIMIT_ANALYZE', 10),
        'exports' => (int) env('API_RATE_LIMIT_EXPORTS', 10),
        'token_creation' => (int) env('API_RATE_LIMIT_TOKEN_CREATION', 10),
        'failed_auth' => (int) env('API_RATE_LIMIT_FAILED_AUTH', 30),
    ],

    'idempotency' => [
        'retention_hours' => (int) env('API_IDEMPOTENCY_RETENTION_HOURS', 24),

        // An unfinished request older than this is treated as abandoned.
        'in_progress_seconds' => (int) env('API_IDEMPOTENCY_IN_PROGRESS_SECONDS', 120),
    ],

    // A generation still waiting after this long is failed instead of spending tokens.
    'generation_queue_expiry_hours' => (int) env('API_GENERATION_QUEUE_EXPIRY_HOURS', 24),

    'export_max_product_ids' => (int) env('API_EXPORT_MAX_PRODUCT_IDS', 1000),

    // Expired tokens stay listed in Settings for this long before being pruned.
    'expired_token_retention_hours' => (int) env('API_EXPIRED_TOKEN_RETENTION_HOURS', 168),

];
