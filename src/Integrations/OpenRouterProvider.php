<?php

declare(strict_types=1);

namespace AiWorkflow\Integrations;

use AiWorkflow\PrismExceptionInspector;
use Illuminate\Http\Client\ConnectionException;
use Integrations\Contracts\ClassifiesFailures;
use Integrations\Contracts\CustomizesRetry;
use Integrations\Contracts\DeclaresRateLimit;
use Integrations\Contracts\IntegrationProvider;
use Integrations\Enums\FailureClass;
use Integrations\RateLimit;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Exceptions\PrismProviderOverloadedException;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\Exceptions\PrismStructuredDecodingException;
use Throwable;

/**
 * Routes ai-workflow's OpenRouter calls through laravel-integrations so the
 * framework owns the circuit breaker, retries, rate limiting, and transport
 * audit. The classifier is the load-bearing part: mapping 402/403 to Client
 * (non-retryable, doesn't trip the breaker) is what stops a billing/access
 * outage from turning into a retry storm.
 */
class OpenRouterProvider implements ClassifiesFailures, CustomizesRetry, DeclaresRateLimit, IntegrationProvider
{
    #[\Override]
    public function classifyFailure(Throwable $e): ?FailureClass
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            // Provider rate limit — OpenRouter is healthy and just pacing us.
            if ($current instanceof PrismRateLimitedException) {
                return FailureClass::Throttle;
            }

            // Provider overloaded — a transient upstream fault worth retrying.
            if ($current instanceof PrismProviderOverloadedException) {
                return FailureClass::Upstream;
            }

            // A malformed structured response isn't a transport fault; AiService
            // recovers via its own fallback-model path. Defer so the breaker
            // neither trips nor retries it.
            if ($current instanceof PrismStructuredDecodingException) {
                return null;
            }

            if ($current instanceof ConnectionException) {
                return FailureClass::Upstream;
            }
        }

        // 5xx → Upstream, 429 → Throttle, 402/403/other 4xx → Client. Prism
        // stashes the status on the exception as a property the core
        // classifier's duck-typing can't read, so we read it explicitly.
        $status = PrismExceptionInspector::httpStatus($e);
        if ($status !== null) {
            return FailureClass::fromStatus($status);
        }

        // A status-less Prism failure (e.g. a 5xx surfaced as a 200 + error
        // body) is a transient upstream fault, not a client mistake.
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof PrismException) {
                return FailureClass::Upstream;
            }
        }

        return null;
    }

    #[\Override]
    public function isRetryable(Throwable $e): ?bool
    {
        // Retryability follows classification: the core falls back to
        // FailureClass::isRetryable() (Upstream/Throttle) when this returns
        // null. We only implement CustomizesRetry for retryDelayMs() below.
        return null;
    }

    #[\Override]
    public function retryDelayMs(Throwable $e, int $attempt, ?int $statusCode): ?int
    {
        $config = $this->retryConfig();
        $status = $statusCode ?? PrismExceptionInspector::httpStatus($e);

        $delay = $this->baseDelayMs($e, $attempt, $status, $config);
        if ($delay === null) {
            // Defer connection/unknown errors to the framework's default backoff.
            return null;
        }

        return $config['jitter'] ? self::applyJitter($delay) : $delay;
    }

    #[\Override]
    public function defaultRateLimit(): ?RateLimit
    {
        $perMinute = config('ai-workflow.openrouter.rate_limit_per_minute');

        return is_int($perMinute) && $perMinute > 0 ? RateLimit::perMinute($perMinute) : null;
    }

    #[\Override]
    public function name(): string
    {
        return 'OpenRouter';
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function credentialRules(): array
    {
        return [
            'api_key' => ['required', 'string'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function metadataRules(): array
    {
        return [];
    }

    /**
     * @return class-string<OpenRouterCredentials>
     */
    #[\Override]
    public function credentialDataClass(): string
    {
        return OpenRouterCredentials::class;
    }

    #[\Override]
    public function metadataDataClass(): ?string
    {
        return null;
    }

    /**
     * The base retry delay (pre-jitter) for an exception, mirroring the backoff
     * ai-workflow applied through Prism's client retry: a fixed pause on rate
     * limits, linear growth on 5xx, and deferral (null) for everything else.
     *
     * @param  array{rate_limit_delay_ms: int, server_error_multiplier_ms: int, jitter: bool}  $config
     */
    private function baseDelayMs(Throwable $e, int $attempt, ?int $status, array $config): ?int
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof PrismRateLimitedException) {
                return $current->retryAfter !== null
                    ? $current->retryAfter * 1000
                    : $config['rate_limit_delay_ms'];
            }

            if ($current instanceof PrismProviderOverloadedException) {
                return $attempt * $config['server_error_multiplier_ms'];
            }
        }

        if ($status === 429) {
            return $config['rate_limit_delay_ms'];
        }

        if ($status !== null && $status >= 500) {
            return $attempt * $config['server_error_multiplier_ms'];
        }

        return null;
    }

    /**
     * @return array{rate_limit_delay_ms: int, server_error_multiplier_ms: int, jitter: bool}
     */
    private function retryConfig(): array
    {
        $config = config('ai-workflow.retry');
        if (! is_array($config)) {
            return ['rate_limit_delay_ms' => 30_000, 'server_error_multiplier_ms' => 2_000, 'jitter' => true];
        }

        return [
            'rate_limit_delay_ms' => is_int($config['rate_limit_delay_ms'] ?? null) ? $config['rate_limit_delay_ms'] : 30_000,
            'server_error_multiplier_ms' => is_int($config['server_error_multiplier_ms'] ?? null) ? $config['server_error_multiplier_ms'] : 2_000,
            'jitter' => is_bool($config['jitter'] ?? null) ? $config['jitter'] : true,
        ];
    }

    /**
     * Apply ±25% random jitter to a delay value.
     */
    private static function applyJitter(int $delay): int
    {
        if ($delay <= 0) {
            return 0;
        }

        $jitter = (int) ($delay * 0.25);

        return max(0, $delay + random_int(-$jitter, $jitter));
    }
}
