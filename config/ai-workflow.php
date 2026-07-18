<?php

declare(strict_types=1);

return [
    // Directory containing prompt markdown files with YAML front-matter.
    'prompts_path' => resource_path('prompts'),

    // Transient-failure handling is owned by laravel-integrations (circuit
    // breaker + retries). `times` is the per-request max attempts; the delay
    // values feed OpenRouterProvider's CustomizesRetry backoff.
    'retry' => [
        'times' => 3,
        'rate_limit_delay_ms' => 30_000,
        'server_error_multiplier_ms' => 2_000,
        'jitter' => true,
    ],

    // OpenRouter integration settings.
    'openrouter' => [
        // Per-minute request budget for the OpenRouter integration, or null
        // for unlimited. The circuit breaker is the primary protection; this
        // is an optional secondary pacing limit honored by the framework.
        'rate_limit_per_minute' => null,
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

    // Maximum tool-use steps per text/stream request.
    'max_steps' => 15,

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

    // Human review UI (ai-workflow:review). Off unless explicitly enabled, so
    // the routes never answer in a deployed app; the command turns it on for
    // the local server it starts.
    'review' => [
        'enabled' => env('AI_WORKFLOW_REVIEW', false),
        'reviewer' => env('AI_WORKFLOW_REVIEWER'),
        'per_page' => 20,

        // Optional AiWorkflow\Eval\ReviewContextResolver implementation, adding
        // links and situational notes to each reviewed request.
        'context' => null,
    ],

    // Per-model pricing in USD per 1M tokens, keyed by provider:model. Used to
    // cost eval runs. A model missing from this map is reported without a cost
    // rather than guessed at, so the numbers are never quietly wrong.
    'model_pricing' => [
        // 'openrouter:anthropic/claude-opus-4.6' => ['input' => 5.00, 'output' => 25.00],
    ],
];
