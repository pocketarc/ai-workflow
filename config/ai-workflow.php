<?php

declare(strict_types=1);

return [
    // Directory containing prompt markdown files with YAML front-matter.
    'prompts_path' => resource_path('prompts'),

    // Retry configuration for transient HTTP errors.
    'retry' => [
        'times' => 3,
        'rate_limit_delay_ms' => 30_000,
        'server_error_multiplier_ms' => 2_000,
        'default_multiplier_ms' => 1_000,
        'jitter' => true,
    ],

    // Client options passed to Prism's withClientOptions().
    'client_options' => [
        'timeout' => 600,
        'curl' => [
            CURLOPT_IGNORE_CONTENT_LENGTH => true,
        ],
    ],

    // Max tokens defaults per response type.
    'max_tokens' => [
        'text' => 16_384,
        'structured' => 32_768,
    ],

    // Request logging — records every AI call with enough detail to replay.
    'logging' => [
        'enabled' => env('AI_WORKFLOW_LOGGING', false),
    ],

    // Response caching — opt-in per prompt via cache_ttl front-matter.
    'cache' => [
        'enabled' => env('AI_WORKFLOW_CACHE', false),
        'store' => env('AI_WORKFLOW_CACHE_STORE'),
    ],

    // Middleware pipeline — global middleware applied to every AI request.
    'middleware' => [],
];
