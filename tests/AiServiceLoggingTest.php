<?php

declare(strict_types=1);

namespace AiWorkflow\Tests;

use AiWorkflow\AiService;
use AiWorkflow\Models\AiWorkflowExecution;
use AiWorkflow\Models\AiWorkflowRequest;
use AiWorkflow\PromptData;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Testing\StructuredResponseFake;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\ValueObjects\Messages\UserMessage;

class AiServiceLoggingTest extends DatabaseTestCase
{
    private function makePrompt(string $id = 'test', string $model = 'openrouter:test-model'): PromptData
    {
        return new PromptData(
            id: $id,
            model: $model,
            prompt: 'You are a helpful assistant.',
        );
    }

    private function makeSchema(): ObjectSchema
    {
        return new ObjectSchema(
            name: 'test',
            description: 'A test schema',
            properties: [
                new StringSchema('answer', 'The answer'),
            ],
            requiredFields: ['answer'],
        );
    }

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
        Prism::fake([
            TextResponseFake::make()->withText('First')->withFinishReason(FinishReason::Stop),
            TextResponseFake::make()->withText('Second')->withFinishReason(FinishReason::Stop),
            TextResponseFake::make()->withText('Third')->withFinishReason(FinishReason::Stop),
        ]);

        $service = app(AiService::class);
        $service->startExecution('token_test');

        $service->sendMessages(collect([new UserMessage('First')]), $this->makePrompt());
        $service->sendMessages(collect([new UserMessage('Second')]), $this->makePrompt());
        $service->sendMessages(collect([new UserMessage('Third')]), $this->makePrompt());

        $execution = $service->endExecution();
        $this->assertNotNull($execution);

        $this->assertSame(3, $execution->requestCount());
        $this->assertGreaterThanOrEqual(0, $execution->totalInputTokens());
        $this->assertGreaterThanOrEqual(0, $execution->totalOutputTokens());
        $this->assertGreaterThanOrEqual(0, $execution->totalTokens());
        $this->assertGreaterThanOrEqual(0, $execution->totalDurationMs());
    }

    public function test_execution_token_tracking_with_no_requests(): void
    {
        $service = app(AiService::class);
        $service->startExecution('empty_execution');
        $execution = $service->endExecution();

        $this->assertNotNull($execution);
        $this->assertSame(0, $execution->requestCount());
        $this->assertSame(0, $execution->totalInputTokens());
        $this->assertSame(0, $execution->totalOutputTokens());
        $this->assertSame(0, $execution->totalTokens());
        $this->assertSame(0, $execution->totalDurationMs());
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
