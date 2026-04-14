<?php

declare(strict_types=1);

namespace AiWorkflow\Tests;

use AiWorkflow\AiService;
use AiWorkflow\Models\AiWorkflowExecution;
use AiWorkflow\Models\AiWorkflowRequest;
use AiWorkflow\PromptData;
use AiWorkflow\Tests\Concerns\MakesTestFixtures;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response as HttpClientResponse;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\StructuredResponseFake;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\Usage;
use ReflectionMethod;

class AiServiceLoggingTest extends DatabaseTestCase
{
    use MakesTestFixtures;

    public function test_request_is_logged_when_enabled(): void
    {
        Prism::fake([
            TextResponseFake::make()
                ->withText('Hello from AI')
                ->withFinishReason(FinishReason::Stop),
        ]);

        $service = app(AiService::class);
        $service->sendMessages(collect([new UserMessage('Hello')]), $this->makePrompt());

        $this->assertDatabaseCount('ai_workflow_requests', 1);

        $request = AiWorkflowRequest::first();
        $this->assertNotNull($request);
        $this->assertSame('test', $request->prompt_id);
        $this->assertSame('sendMessages', $request->method);
        $this->assertSame('openrouter', $request->provider);
        $this->assertSame('test-model', $request->model);
        $this->assertSame('Hello from AI', $request->response_text);
        $this->assertSame('stop', $request->finish_reason);
        $this->assertNull($request->execution_id);
        $this->assertNull($request->error);
        $this->assertGreaterThanOrEqual(0, $request->duration_ms);
    }

    public function test_request_is_not_logged_when_disabled(): void
    {
        config()->set('ai-workflow.logging.enabled', false);

        Prism::fake([
            TextResponseFake::make()
                ->withText('Hello')
                ->withFinishReason(FinishReason::Stop),
        ]);

        $service = app(AiService::class);
        $service->sendMessages(collect([new UserMessage('Hello')]), $this->makePrompt());

        $this->assertDatabaseCount('ai_workflow_requests', 0);
    }

    public function test_execution_groups_requests(): void
    {
        Prism::fake([
            TextResponseFake::make()->withText('First')->withFinishReason(FinishReason::Stop),
            TextResponseFake::make()->withText('Second')->withFinishReason(FinishReason::Stop),
        ]);

        $service = app(AiService::class);
        $service->startExecution('test_workflow', ['ticket_id' => 42]);

        $service->sendMessages(collect([new UserMessage('First')]), $this->makePrompt());
        $service->sendMessages(collect([new UserMessage('Second')]), $this->makePrompt());

        $execution = $service->endExecution();

        $this->assertNotNull($execution);
        $this->assertInstanceOf(AiWorkflowExecution::class, $execution);
        $this->assertSame('test_workflow', $execution->name);
        $this->assertSame(['ticket_id' => 42], $execution->metadata);

        $this->assertDatabaseCount('ai_workflow_requests', 2);

        $requests = AiWorkflowRequest::where('execution_id', $execution->id)->get();
        $this->assertCount(2, $requests);
        $this->assertSame('First', $requests[0]->response_text);
        $this->assertSame('Second', $requests[1]->response_text);
    }

    public function test_failed_request_is_logged_with_error(): void
    {
        Prism::fake([
            TextResponseFake::make()
                ->withText('Bad response')
                ->withFinishReason(FinishReason::Unknown),
        ]);

        $service = app(AiService::class);

        try {
            $service->sendMessages(collect([new UserMessage('Hello')]), $this->makePrompt());
        } catch (\Throwable) {
            // Expected — finish reason Unknown throws.
        }

        $this->assertDatabaseCount('ai_workflow_requests', 1);

        $request = AiWorkflowRequest::first();
        $this->assertNotNull($request);
        $this->assertNotNull($request->error);
        $this->assertStringContainsString('Unexpected AI finish reason', $request->error);
        $this->assertSame(PrismException::class, $request->error_class);
        $this->assertNull($request->http_status);
        $this->assertNull($request->response_body);
    }

    public function test_extract_http_details_reads_prism_exception_fields(): void
    {
        $exception = PrismException::providerResponseError(
            'OpenRouter Bad Request: Provider returned error',
            httpStatus: 400,
            responseBody: '{"error":{"message":"bad"}}',
        );

        $details = $this->invokeExtractHttpDetails($exception);

        $this->assertSame(400, $details['status']);
        $this->assertSame('{"error":{"message":"bad"}}', $details['body']);
    }

    public function test_extract_http_details_walks_chain_for_request_exception(): void
    {
        $psrResponse = new Response(400, [], '{"error":"bad"}');
        $httpResponse = new HttpClientResponse($psrResponse);
        $requestException = new RequestException($httpResponse);
        $wrapper = PrismException::providerRequestError('openrouter:test-model', $requestException);

        $details = $this->invokeExtractHttpDetails($wrapper);

        $this->assertSame(400, $details['status']);
        $this->assertSame('{"error":"bad"}', $details['body']);
    }

    public function test_extract_http_details_returns_null_without_http_context(): void
    {
        $details = $this->invokeExtractHttpDetails(new \RuntimeException('boom'));

        $this->assertNull($details['status']);
        $this->assertNull($details['body']);
    }

    public function test_sanitize_response_body_truncates_oversized_body(): void
    {
        $body = str_repeat('a', 70_000);

        $sanitized = $this->invokeSanitizeResponseBody($body);

        $this->assertNotNull($sanitized);
        $this->assertStringEndsWith('…[truncated]', $sanitized);
        $this->assertSame(65_536 + strlen('…[truncated]'), strlen($sanitized));
    }

    public function test_sanitize_response_body_strips_invalid_utf8(): void
    {
        $invalid = "valid prefix \xc3\x28 suffix";
        $this->assertFalse(mb_check_encoding($invalid, 'UTF-8'));

        $sanitized = $this->invokeSanitizeResponseBody($invalid);

        $this->assertNotNull($sanitized);
        $this->assertTrue(mb_check_encoding($sanitized, 'UTF-8'));
    }

    /**
     * @return array{status: ?int, body: ?string}
     */
    private function invokeExtractHttpDetails(?\Throwable $error): array
    {
        $method = new ReflectionMethod(AiService::class, 'extractHttpDetails');
        /** @var array{status: ?int, body: ?string} $result */
        $result = $method->invoke(app(AiService::class), $error);

        return $result;
    }

    private function invokeSanitizeResponseBody(?string $body, int $limit = 65536): ?string
    {
        $method = new ReflectionMethod(AiService::class, 'sanitizeResponseBody');
        /** @var ?string $result */
        $result = $method->invoke(app(AiService::class), $body, $limit);

        return $result;
    }

    public function test_start_execution_without_logging_is_noop(): void
    {
        config()->set('ai-workflow.logging.enabled', false);

        $service = app(AiService::class);
        $service->startExecution('noop_workflow');

        $execution = $service->endExecution();

        $this->assertNull($execution);
        $this->assertDatabaseCount('ai_workflow_executions', 0);
    }

    public function test_structured_request_is_logged_with_schema(): void
    {
        Prism::fake([
            StructuredResponseFake::make()
                ->withStructured(['answer' => 'test'])
                ->withFinishReason(FinishReason::Stop),
        ]);

        $service = app(AiService::class);
        $service->sendStructuredMessages(
            collect([new UserMessage('Hello')]),
            $this->makePrompt(),
            $this->makeSchema(),
        );

        $this->assertDatabaseCount('ai_workflow_requests', 1);

        $request = AiWorkflowRequest::first();
        $this->assertNotNull($request);
        $this->assertSame('sendStructuredMessages', $request->method);
        $this->assertSame(['answer' => 'test'], $request->structured_response);
        $this->assertNotNull($request->schema);
        $this->assertIsArray($request->schema);
    }

    public function test_execution_token_tracking(): void
    {
        $usage = new Usage(100, 50);

        Prism::fake([
            TextResponseFake::make()->withText('First')->withFinishReason(FinishReason::Stop)->withUsage($usage),
            TextResponseFake::make()->withText('Second')->withFinishReason(FinishReason::Stop)->withUsage($usage),
            TextResponseFake::make()->withText('Third')->withFinishReason(FinishReason::Stop)->withUsage($usage),
        ]);

        $service = app(AiService::class);
        $service->startExecution('token_test');

        $service->sendMessages(collect([new UserMessage('First')]), $this->makePrompt());
        $service->sendMessages(collect([new UserMessage('Second')]), $this->makePrompt());
        $service->sendMessages(collect([new UserMessage('Third')]), $this->makePrompt());

        $execution = $service->endExecution();
        $this->assertNotNull($execution);

        $this->assertSame(3, $execution->request_count);
        $this->assertSame(300, $execution->total_input_tokens);
        $this->assertSame(150, $execution->total_output_tokens);
        $this->assertSame(450, $execution->total_tokens);
        $this->assertGreaterThanOrEqual(0, $execution->total_duration_ms);
    }

    public function test_execution_token_tracking_with_no_requests(): void
    {
        $service = app(AiService::class);
        $service->startExecution('empty_execution');
        $execution = $service->endExecution();

        $this->assertNotNull($execution);
        $this->assertSame(0, $execution->request_count);
        $this->assertSame(0, $execution->total_input_tokens);
        $this->assertSame(0, $execution->total_output_tokens);
        $this->assertSame(0, $execution->total_tokens);
        $this->assertSame(0, $execution->total_duration_ms);
    }

    public function test_prompt_tags_are_stored(): void
    {
        Prism::fake([
            TextResponseFake::make()->withText('Hello')->withFinishReason(FinishReason::Stop),
        ]);

        $prompt = new PromptData(
            id: 'test',
            model: 'openrouter:test-model',
            prompt: 'You are a helpful assistant.',
            tags: ['classification', 'intent'],
        );

        $service = app(AiService::class);
        $service->sendMessages(collect([new UserMessage('Hello')]), $prompt);

        $request = AiWorkflowRequest::first();
        $this->assertNotNull($request);
        $this->assertSame(['classification', 'intent'], $request->tags);
    }

    public function test_service_tags_are_stored(): void
    {
        Prism::fake([
            TextResponseFake::make()->withText('Hello')->withFinishReason(FinishReason::Stop),
        ]);

        $service = app(AiService::class);
        $service->setTags(['billing', 'urgent']);
        $service->sendMessages(collect([new UserMessage('Hello')]), $this->makePrompt());

        $request = AiWorkflowRequest::first();
        $this->assertNotNull($request);
        $this->assertSame(['billing', 'urgent'], $request->tags);
    }

    public function test_prompt_and_service_tags_are_merged(): void
    {
        Prism::fake([
            TextResponseFake::make()->withText('Hello')->withFinishReason(FinishReason::Stop),
        ]);

        $prompt = new PromptData(
            id: 'test',
            model: 'openrouter:test-model',
            prompt: 'You are a helpful assistant.',
            tags: ['classification', 'shared'],
        );

        $service = app(AiService::class);
        $service->setTags(['billing', 'shared']);
        $service->sendMessages(collect([new UserMessage('Hello')]), $prompt);

        $request = AiWorkflowRequest::first();
        $this->assertNotNull($request);
        $this->assertSame(['classification', 'shared', 'billing'], $request->tags);
    }

    public function test_tags_null_when_none_set(): void
    {
        Prism::fake([
            TextResponseFake::make()->withText('Hello')->withFinishReason(FinishReason::Stop),
        ]);

        $service = app(AiService::class);
        $service->sendMessages(collect([new UserMessage('Hello')]), $this->makePrompt());

        $request = AiWorkflowRequest::first();
        $this->assertNotNull($request);
        $this->assertNull($request->tags);
    }

    public function test_with_tag_scope_filters_correctly(): void
    {
        Prism::fake([
            TextResponseFake::make()->withText('First')->withFinishReason(FinishReason::Stop),
            TextResponseFake::make()->withText('Second')->withFinishReason(FinishReason::Stop),
        ]);

        $service = app(AiService::class);

        $service->setTags(['billing']);
        $service->sendMessages(collect([new UserMessage('First')]), $this->makePrompt());

        $service->setTags(['support']);
        $service->sendMessages(collect([new UserMessage('Second')]), $this->makePrompt());

        $this->assertCount(1, AiWorkflowRequest::withTag('billing')->get());
        $this->assertCount(1, AiWorkflowRequest::withTag('support')->get());
        $this->assertCount(0, AiWorkflowRequest::withTag('nonexistent')->get());
    }

    public function test_with_any_tag_scope_filters_correctly(): void
    {
        Prism::fake([
            TextResponseFake::make()->withText('First')->withFinishReason(FinishReason::Stop),
            TextResponseFake::make()->withText('Second')->withFinishReason(FinishReason::Stop),
            TextResponseFake::make()->withText('Third')->withFinishReason(FinishReason::Stop),
        ]);

        $service = app(AiService::class);

        $service->setTags(['billing']);
        $service->sendMessages(collect([new UserMessage('First')]), $this->makePrompt());

        $service->setTags(['support']);
        $service->sendMessages(collect([new UserMessage('Second')]), $this->makePrompt());

        $service->setTags(['other']);
        $service->sendMessages(collect([new UserMessage('Third')]), $this->makePrompt());

        $this->assertCount(2, AiWorkflowRequest::withAnyTag(['billing', 'support'])->get());
    }

    public function test_stream_request_is_logged(): void
    {
        Prism::fake([
            TextResponseFake::make()
                ->withText('Streamed')
                ->withFinishReason(FinishReason::Stop)
                ->withUsage(new Usage(80, 40)),
        ]);

        $service = app(AiService::class);

        foreach ($service->streamMessages(collect([new UserMessage('Hello')]), $this->makePrompt()) as $event) {
            // Consume.
        }

        $this->assertDatabaseCount('ai_workflow_requests', 1);

        $request = AiWorkflowRequest::first();
        $this->assertNotNull($request);
        $this->assertSame('streamMessages', $request->method);
        $this->assertSame('openrouter', $request->provider);
        $this->assertSame('test-model', $request->model);
        $this->assertSame('stop', $request->finish_reason);
        $this->assertSame(80, $request->input_tokens);
        $this->assertSame(40, $request->output_tokens);
        $this->assertNull($request->response_text);
    }

    public function test_cache_hit_does_not_create_log_record(): void
    {
        config()->set('ai-workflow.cache.enabled', true);
        config()->set('ai-workflow.cache.store', 'array');

        $prompt = new PromptData(
            id: 'test',
            model: 'openrouter:test-model',
            prompt: 'You are a helpful assistant.',
            cacheTtl: 3600,
        );

        Prism::fake([
            TextResponseFake::make()->withText('Cached response')->withFinishReason(FinishReason::Stop),
        ]);

        $service = app(AiService::class);

        $service->sendMessages(collect([new UserMessage('Hello')]), $prompt);
        $this->assertDatabaseCount('ai_workflow_requests', 1);

        // Second call should be a cache hit — no new log record.
        $service->sendMessages(collect([new UserMessage('Hello')]), $prompt);
        $this->assertDatabaseCount('ai_workflow_requests', 1);
    }

    public function test_thought_tokens_are_logged(): void
    {
        Prism::fake([
            TextResponseFake::make()
                ->withText('Hello')
                ->withFinishReason(FinishReason::Stop)
                ->withUsage(new Usage(100, 50, thoughtTokens: 25)),
        ]);

        $service = app(AiService::class);
        $service->sendMessages(collect([new UserMessage('Hello')]), $this->makePrompt());

        $request = AiWorkflowRequest::first();
        $this->assertNotNull($request);
        $this->assertSame(100, $request->input_tokens);
        $this->assertSame(50, $request->output_tokens);
        $this->assertSame(25, $request->thought_tokens);
    }

    public function test_thought_tokens_null_when_not_present(): void
    {
        Prism::fake([
            TextResponseFake::make()
                ->withText('Hello')
                ->withFinishReason(FinishReason::Stop)
                ->withUsage(new Usage(100, 50)),
        ]);

        $service = app(AiService::class);
        $service->sendMessages(collect([new UserMessage('Hello')]), $this->makePrompt());

        $request = AiWorkflowRequest::first();
        $this->assertNotNull($request);
        $this->assertNull($request->thought_tokens);
    }

    public function test_execution_thought_token_tracking(): void
    {
        $usage = new Usage(100, 50, thoughtTokens: 25);

        Prism::fake([
            TextResponseFake::make()->withText('First')->withFinishReason(FinishReason::Stop)->withUsage($usage),
            TextResponseFake::make()->withText('Second')->withFinishReason(FinishReason::Stop)->withUsage($usage),
            TextResponseFake::make()->withText('Third')->withFinishReason(FinishReason::Stop)->withUsage($usage),
        ]);

        $service = app(AiService::class);
        $service->startExecution('thought_token_test');

        $service->sendMessages(collect([new UserMessage('First')]), $this->makePrompt());
        $service->sendMessages(collect([new UserMessage('Second')]), $this->makePrompt());
        $service->sendMessages(collect([new UserMessage('Third')]), $this->makePrompt());

        $execution = $service->endExecution();
        $this->assertNotNull($execution);

        $this->assertSame(75, $execution->total_thought_tokens);
        $this->assertSame(450, $execution->total_tokens);
    }

    public function test_messages_are_serialized_correctly(): void
    {
        Prism::fake([
            TextResponseFake::make()
                ->withText('Response')
                ->withFinishReason(FinishReason::Stop),
        ]);

        $service = app(AiService::class);
        $service->sendMessages(
            collect([new UserMessage('Hello world')]),
            $this->makePrompt(),
        );

        $request = AiWorkflowRequest::first();
        $this->assertNotNull($request);
        $this->assertIsArray($request->messages);
        $this->assertCount(1, $request->messages);
        $this->assertSame('user', $request->messages[0]['type']);
        $this->assertSame('Hello world', $request->messages[0]['content']);
    }
}
