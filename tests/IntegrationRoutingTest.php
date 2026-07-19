<?php

declare(strict_types=1);

namespace AiWorkflow\Tests;

use AiWorkflow\AiService;
use AiWorkflow\Events\AiWorkflowRequestFailed;
use AiWorkflow\Integrations\OpenRouterProvider;
use AiWorkflow\Models\AiWorkflowRequest;
use AiWorkflow\PromptData;
use AiWorkflow\Tests\Concerns\MakesTestFixtures;
use Generator;
use Illuminate\Support\Facades\Event;
use Integrations\Enums\FailureClass;
use Integrations\Models\Integration;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Enums\Provider as ProviderEnum;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Facades\Prism;
use Prism\Prism\PrismManager;
use Prism\Prism\Providers\Provider;
use Prism\Prism\Streaming\Events\StreamStartEvent;
use Prism\Prism\Testing\PrismFake;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\Text\Request as TextRequest;
use Prism\Prism\Text\Response as TextResponse;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use RuntimeException;
use Throwable;

/**
 * End-to-end coverage that ai-workflow's OpenRouter calls now flow through the
 * laravel-integrations executor, and that the failure classifier behaves the
 * way the migration depends on: 402/403 stop immediately (no retry storm), 5xx
 * retries, and unmanaged providers fall back to direct Prism.
 */
class IntegrationRoutingTest extends DatabaseTestCase
{
    use MakesTestFixtures;

    /**
     * Install a PrismManager whose text() throws $exception, counting calls.
     */
    private function fakeTextThrowing(Throwable $exception): PrismFake
    {
        $fake = new class($exception) extends PrismFake
        {
            public int $calls = 0;

            public function __construct(private readonly Throwable $exception)
            {
                parent::__construct([]);
            }

            public function text(TextRequest $request): TextResponse
            {
                $this->calls++;

                throw $this->exception;
            }
        };

        app()->instance(PrismManager::class, new class($fake) extends PrismManager
        {
            public function __construct(private readonly PrismFake $fake) {}

            public function resolve(ProviderEnum|string $name, array $providerConfig = []): Provider
            {
                return $this->fake;
            }
        });

        return $fake;
    }

    private function openRouterIntegration(): Integration
    {
        return Integration::query()->where('provider', 'openrouter')->firstOrFail();
    }

    public function test_billing_error_is_not_retried_and_keeps_breaker_closed(): void
    {
        $fake = $this->fakeTextThrowing(PrismException::providerResponseError(
            'OpenRouter: insufficient credits',
            httpStatus: 402,
            responseBody: '{"error":{"message":"insufficient credits"}}',
        ));

        $service = app(AiService::class);

        try {
            $service->sendMessages(collect([new UserMessage('Hello')]), $this->makePrompt());
            $this->fail('Expected the 402 to surface.');
        } catch (PrismException $e) {
            $this->assertSame(402, $e->httpStatus);
        }

        // The whole point of the migration: a 402 is a Client fault, so it runs
        // exactly once and never trips the shared breaker.
        $this->assertSame(1, $fake->calls);

        $integration = $this->openRouterIntegration();
        $this->assertSame(0, $integration->consecutive_failures);
        $this->assertSame('healthy', $integration->health_status->value);

        // The AI-domain record still captures the transport detail.
        $request = AiWorkflowRequest::first();
        $this->assertNotNull($request);
        $this->assertSame(402, $request->http_status);
        $this->assertSame(PrismException::class, $request->error_class);
        $this->assertNotNull($request->response_body);
    }

    public function test_server_error_is_retried_up_to_max_attempts(): void
    {
        config()->set('ai-workflow.retry.times', 3);
        config()->set('ai-workflow.retry.jitter', false);
        config()->set('ai-workflow.retry.server_error_multiplier_ms', 0);

        $fake = $this->fakeTextThrowing(PrismException::providerResponseError(
            'OpenRouter: upstream unavailable',
            httpStatus: 503,
            responseBody: '{"error":"unavailable"}',
        ));

        $service = app(AiService::class);

        try {
            $service->sendMessages(collect([new UserMessage('Hello')]), $this->makePrompt());
            $this->fail('Expected the 503 to surface after exhausting retries.');
        } catch (PrismException $e) {
            $this->assertSame(503, $e->httpStatus);
        }

        // An Upstream fault is retryable, so it runs maxAttempts times and each
        // failure counts toward the breaker (threshold is higher, so it stays
        // closed here).
        $this->assertSame(3, $fake->calls);
        $this->assertSame(3, $this->openRouterIntegration()->consecutive_failures);
    }

    public function test_duplicate_integrations_for_a_provider_fail_loudly(): void
    {
        // The base TestCase already seeds one openrouter integration; a second
        // makes resolution ambiguous, which must throw rather than silently
        // pick one account's credentials and breaker.
        $this->createIntegration(
            providerKey: 'openrouter',
            providerClass: OpenRouterProvider::class,
            credentials: ['api_key' => 'second-key'],
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Multiple Integration rows exist for provider 'openrouter'");

        app(AiService::class)->sendMessages(collect([new UserMessage('Hello')]), $this->makePrompt());
    }

    public function test_integration_misconfiguration_is_logged_and_dispatched(): void
    {
        Event::fake([AiWorkflowRequestFailed::class]);

        $this->createIntegration(
            providerKey: 'openrouter',
            providerClass: OpenRouterProvider::class,
            credentials: ['api_key' => 'second-key'],
        );

        try {
            app(AiService::class)->sendMessages(collect([new UserMessage('Hello')]), $this->makePrompt());
            $this->fail('Expected the duplicate integration to throw.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Multiple Integration rows exist', $e->getMessage());
        }

        // The misconfiguration flows through the normal failure path: a logged
        // request and a dispatched failure event, not a silent throw.
        $request = AiWorkflowRequest::first();
        $this->assertNotNull($request);
        $this->assertStringContainsString('Multiple Integration rows exist', (string) $request->error);
        Event::assertDispatched(AiWorkflowRequestFailed::class);
    }

    public function test_stream_misconfiguration_is_logged_and_dispatched(): void
    {
        Event::fake([AiWorkflowRequestFailed::class]);

        $this->createIntegration(
            providerKey: 'openrouter',
            providerClass: OpenRouterProvider::class,
            credentials: ['api_key' => 'second-key'],
        );

        try {
            foreach (app(AiService::class)->streamMessages(collect([new UserMessage('Hello')]), $this->makePrompt()) as $event) {
                // Drain the generator; resolution throws on first iteration.
            }
            $this->fail('Expected the duplicate integration to throw.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Multiple Integration rows exist', $e->getMessage());
        }

        $request = AiWorkflowRequest::first();
        $this->assertNotNull($request);
        $this->assertStringContainsString('Multiple Integration rows exist', (string) $request->error);
        Event::assertDispatched(AiWorkflowRequestFailed::class);
    }

    public function test_stream_failure_counts_toward_breaker_and_health(): void
    {
        $exception = PrismException::providerResponseError(
            'OpenRouter: upstream unavailable',
            httpStatus: 503,
            responseBody: '{"error":"unavailable"}',
        );

        $fake = new class($exception) extends PrismFake
        {
            public function __construct(private readonly Throwable $exception)
            {
                parent::__construct([]);
            }

            public function stream(TextRequest $request): Generator
            {
                yield new StreamStartEvent(
                    id: 'fake',
                    timestamp: time(),
                    model: 'test-model',
                    provider: 'fake',
                );

                throw $this->exception;
            }
        };

        app()->instance(PrismManager::class, new class($fake) extends PrismManager
        {
            public function __construct(private readonly PrismFake $fake) {}

            public function resolve(ProviderEnum|string $name, array $providerConfig = []): Provider
            {
                return $this->fake;
            }
        });

        try {
            foreach (app(AiService::class)->streamMessages(collect([new UserMessage('Hello')]), $this->makePrompt()) as $event) {
                // Drain until the fake throws mid-stream.
            }
            $this->fail('Expected the 503 to surface.');
        } catch (PrismException $e) {
            $this->assertSame(503, $e->httpStatus);
        }

        // Streaming bypasses the executor, so the service itself must feed
        // the outcome into the shared health/breaker accounting.
        $this->assertSame(1, $this->openRouterIntegration()->consecutive_failures);
    }

    public function test_stream_success_resets_health(): void
    {
        Prism::fake([
            TextResponseFake::make()->withText('Streamed')->withFinishReason(FinishReason::Stop),
        ]);

        $this->openRouterIntegration()->recordFailure(FailureClass::Upstream);
        $this->assertSame(1, $this->openRouterIntegration()->consecutive_failures);

        foreach (app(AiService::class)->streamMessages(collect([new UserMessage('Hello')]), $this->makePrompt()) as $event) {
            // Drain to completion.
        }

        $this->assertSame(0, $this->openRouterIntegration()->consecutive_failures);
    }

    public function test_cache_hit_still_validates_the_managed_integration(): void
    {
        config()->set('ai-workflow.cache.enabled', true);
        config()->set('ai-workflow.cache.store', 'array');

        $prompt = new PromptData(
            id: 'cached',
            model: 'openrouter:test-model',
            prompt: 'You are helpful.',
            cacheTtl: 3600,
        );

        Prism::fake([
            TextResponseFake::make()->withText('Cached'),
        ]);

        $service = app(AiService::class);

        // Warm the cache with a valid single integration.
        $service->sendMessages(collect([new UserMessage('Hello')]), $prompt);

        // A duplicate introduced while the cache is warm must still be caught:
        // resolution runs before the cache short-circuit.
        $this->createIntegration(
            providerKey: 'openrouter',
            providerClass: OpenRouterProvider::class,
            credentials: ['api_key' => 'second-key'],
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Multiple Integration rows exist for provider 'openrouter'");

        $service->sendMessages(collect([new UserMessage('Hello')]), $prompt);
    }

    public function test_unmanaged_provider_falls_back_to_direct_prism(): void
    {
        // anthropic has no registered provider/integration, so the call should
        // skip the executor entirely and hit Prism directly.
        Prism::fake([
            TextResponseFake::make()->withText('Direct response'),
        ]);

        $prompt = new PromptData(
            id: 'direct',
            model: 'anthropic:claude-4',
            prompt: 'You are helpful.',
        );

        $service = app(AiService::class);
        $response = $service->sendMessages(collect([new UserMessage('Hello')]), $prompt);

        $this->assertSame('Direct response', $response->text);
        $this->assertDatabaseCount('integration_requests', 0);
    }
}
