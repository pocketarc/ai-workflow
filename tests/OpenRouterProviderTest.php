<?php

declare(strict_types=1);

namespace AiWorkflow\Tests;

use AiWorkflow\Integrations\OpenRouterCredentials;
use AiWorkflow\Integrations\OpenRouterProvider;
use AiWorkflow\PrismExceptionInspector;
use Illuminate\Http\Client\ConnectionException;
use Integrations\Contracts\ClassifiesFailures;
use Integrations\Contracts\CustomizesRetry;
use Integrations\Contracts\DeclaresRateLimit;
use Integrations\Contracts\IntegrationProvider;
use Integrations\Enums\FailureClass;
use Integrations\Enums\RateLimitWindow;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Exceptions\PrismProviderOverloadedException;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\Exceptions\PrismRequestTooLargeException;
use Prism\Prism\Exceptions\PrismStructuredDecodingException;
use RuntimeException;

class OpenRouterProviderTest extends TestCase
{
    private function providerResponse(string $message, int $status): PrismException
    {
        $exception = new PrismException($message);
        $exception->httpStatus = $status;

        return $exception;
    }

    public function test_implements_required_contracts(): void
    {
        $provider = new OpenRouterProvider;

        $this->assertInstanceOf(IntegrationProvider::class, $provider);
        $this->assertInstanceOf(ClassifiesFailures::class, $provider);
        $this->assertInstanceOf(CustomizesRetry::class, $provider);
        $this->assertInstanceOf(DeclaresRateLimit::class, $provider);
    }

    // --- Failure classification ---

    public function test_billing_and_auth_4xx_classify_as_client(): void
    {
        $provider = new OpenRouterProvider;

        // 402/403 are the storm trigger: Client means non-retryable and the
        // breaker never trips.
        $this->assertSame(FailureClass::Client, $provider->classifyFailure($this->providerResponse('payment required', 402)));
        $this->assertSame(FailureClass::Client, $provider->classifyFailure($this->providerResponse('forbidden', 403)));
        $this->assertSame(FailureClass::Client, $provider->classifyFailure($this->providerResponse('bad request', 400)));
    }

    public function test_429_classifies_as_throttle(): void
    {
        $provider = new OpenRouterProvider;

        $this->assertSame(FailureClass::Throttle, $provider->classifyFailure($this->providerResponse('slow down', 429)));
    }

    public function test_5xx_classifies_as_upstream(): void
    {
        $provider = new OpenRouterProvider;

        $this->assertSame(FailureClass::Upstream, $provider->classifyFailure($this->providerResponse('server error', 500)));
        $this->assertSame(FailureClass::Upstream, $provider->classifyFailure($this->providerResponse('unavailable', 503)));
    }

    public function test_prism_exception_types_classify_explicitly(): void
    {
        $provider = new OpenRouterProvider;

        $this->assertSame(FailureClass::Throttle, $provider->classifyFailure(PrismRateLimitedException::make()));
        $this->assertSame(FailureClass::Upstream, $provider->classifyFailure(PrismProviderOverloadedException::make('openrouter')));
        $this->assertSame(FailureClass::Client, $provider->classifyFailure(PrismRequestTooLargeException::make('openrouter')));
        $this->assertSame(FailureClass::Upstream, $provider->classifyFailure(new ConnectionException('network down')));
    }

    public function test_bare_prism_exception_without_status_is_upstream(): void
    {
        $provider = new OpenRouterProvider;

        // Prism surfaces some provider faults (e.g. a 500 returned as 200 + an
        // error body) as a status-less PrismException; treat those as transient.
        $this->assertSame(FailureClass::Upstream, $provider->classifyFailure(new PrismException('OpenRouter: unknown error')));
    }

    public function test_status_is_read_through_a_body_only_wrapper(): void
    {
        // Prism sometimes rethrows with only the body attached while an inner
        // exception still carries the status. Dropping it here would turn a
        // billing 402 into a retryable Upstream fault.
        $inner = new PrismException('payment required');
        $inner->httpStatus = 402;

        $outer = new PrismException('OpenRouter request failed', previous: $inner);
        $outer->responseBody = '{"error":{"message":"insufficient credits"}}';

        $this->assertSame(
            ['status' => 402, 'body' => '{"error":{"message":"insufficient credits"}}'],
            PrismExceptionInspector::extract($outer),
        );
        $this->assertSame(FailureClass::Client, (new OpenRouterProvider)->classifyFailure($outer));
    }

    public function test_structured_decoding_defers_so_fallback_handles_it(): void
    {
        $provider = new OpenRouterProvider;

        $this->assertNull($provider->classifyFailure(PrismStructuredDecodingException::make('invalid json')));
    }

    public function test_unrelated_exception_defers_to_core_classifier(): void
    {
        $provider = new OpenRouterProvider;

        $this->assertNull($provider->classifyFailure(new RuntimeException('mystery')));
    }

    // --- Retry decision + backoff ---

    public function test_is_retryable_defers_to_classification(): void
    {
        $this->assertNull((new OpenRouterProvider)->isRetryable(new RuntimeException('x')));
    }

    public function test_retry_delay_uses_rate_limit_delay_for_429(): void
    {
        config()->set('ai-workflow.retry.jitter', false);
        config()->set('ai-workflow.retry.rate_limit_delay_ms', 30_000);

        $provider = new OpenRouterProvider;

        // The executor can't read Prism's status property, so it passes null;
        // the provider re-reads it from the exception.
        $this->assertSame(30_000, $provider->retryDelayMs($this->providerResponse('slow', 429), 1, null));
        $this->assertSame(30_000, $provider->retryDelayMs(PrismRateLimitedException::make(), 1, null));
    }

    public function test_retry_delay_honors_retry_after(): void
    {
        config()->set('ai-workflow.retry.jitter', false);

        $provider = new OpenRouterProvider;

        $this->assertSame(10_000, $provider->retryDelayMs(PrismRateLimitedException::make(retryAfter: 10), 1, null));
    }

    public function test_retry_delay_grows_linearly_for_5xx(): void
    {
        config()->set('ai-workflow.retry.jitter', false);
        config()->set('ai-workflow.retry.server_error_multiplier_ms', 2_000);

        $provider = new OpenRouterProvider;

        $this->assertSame(4_000, $provider->retryDelayMs($this->providerResponse('boom', 500), 2, null));
        $this->assertSame(6_000, $provider->retryDelayMs(PrismProviderOverloadedException::make('openrouter'), 3, null));
    }

    public function test_retry_delay_defers_for_non_retryable_status(): void
    {
        config()->set('ai-workflow.retry.jitter', false);

        $provider = new OpenRouterProvider;

        $this->assertNull($provider->retryDelayMs($this->providerResponse('bad', 400), 1, null));
    }

    public function test_negative_retry_delays_fall_back_to_defaults(): void
    {
        config()->set('ai-workflow.retry.jitter', false);
        config()->set('ai-workflow.retry.rate_limit_delay_ms', -5_000);
        config()->set('ai-workflow.retry.server_error_multiplier_ms', -1_000);

        $provider = new OpenRouterProvider;

        $this->assertSame(30_000, $provider->retryDelayMs($this->providerResponse('slow', 429), 1, null));
        $this->assertSame(2_000, $provider->retryDelayMs($this->providerResponse('boom', 500), 1, null));
    }

    public function test_retry_delay_applies_jitter(): void
    {
        config()->set('ai-workflow.retry.jitter', true);
        config()->set('ai-workflow.retry.server_error_multiplier_ms', 2_000);

        $provider = new OpenRouterProvider;
        $exception = $this->providerResponse('boom', 500);

        $delays = [];
        for ($i = 0; $i < 20; $i++) {
            $delay = $provider->retryDelayMs($exception, 2, null);
            $this->assertNotNull($delay);
            $delays[] = $delay;
        }

        // Base 4000 ± 25% = 3000..5000.
        foreach ($delays as $delay) {
            $this->assertGreaterThanOrEqual(3_000, $delay);
            $this->assertLessThanOrEqual(5_000, $delay);
        }

        $this->assertGreaterThan(1, count(array_unique($delays)));
    }

    // --- Rate limit + metadata ---

    public function test_default_rate_limit_is_null_unless_configured(): void
    {
        $this->assertNull((new OpenRouterProvider)->defaultRateLimit());

        config()->set('ai-workflow.openrouter.rate_limit_per_minute', 120);

        $limit = (new OpenRouterProvider)->defaultRateLimit();
        $this->assertNotNull($limit);
        $this->assertSame(120, $limit->limit);
        $this->assertSame(60, $limit->windowSeconds);
        $this->assertSame(RateLimitWindow::Fixed, $limit->window);
    }

    public function test_metadata(): void
    {
        $provider = new OpenRouterProvider;

        $this->assertSame('OpenRouter', $provider->name());
        $this->assertArrayHasKey('api_key', $provider->credentialRules());
        $this->assertSame([], $provider->metadataRules());
        $this->assertSame(OpenRouterCredentials::class, $provider->credentialDataClass());
        $this->assertNull($provider->metadataDataClass());
    }
}
