<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL'),
        'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
        'connect_timeout' => (int) env('GEMINI_CONNECT_TIMEOUT', 10),
        'timeout' => (int) env('GEMINI_TIMEOUT', 60),
        'max_retries' => (int) env('GEMINI_MAX_RETRIES', 1),
        'max_output_tokens' => (int) env('GEMINI_MAX_OUTPUT_TOKENS', 8192),
        'per_minute_limit' => (int) env('AI_GENERATIONS_PER_MINUTE', 5),
        'user_daily_limit' => (int) env('AI_USER_DAILY_LIMIT', 20),
        'app_daily_limit' => (int) env('AI_APP_DAILY_LIMIT', 50),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
