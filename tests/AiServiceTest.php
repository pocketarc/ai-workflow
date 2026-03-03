<?php

declare(strict_types=1);

namespace AiWorkflow\Tests;

use AiWorkflow\AiService;
use AiWorkflow\PromptData;
use Illuminate\Support\Collection;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Facades\Tool;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Structured\Response as StructuredResponse;
use Prism\Prism\Testing\StructuredResponseFake;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\Text\Response;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;
use RuntimeException;

class AiServiceTest extends TestCase
{
    private function makePrompt(
        string $id = 'test',
        string $model = 'openrouter:test-model',
        ?string $fallbackModel = null,
    ): PromptData {
        return new PromptData(
            id: $id,
            model: $model,
            prompt: 'You are a helpful assistant.',
            fallbackModel: $fallbackModel,
        );
    }

    private function makeTextResponse(
        string $text = 'Hello from AI',
        FinishReason $finishReason = FinishReason::Stop,
    ): Response {
        return new Response(
            steps: new Collection,
            text: $text,
            finishReason: $finishReason,
            toolCalls: [],
            toolResults: [],
            usage: new Usage(100, 50),
            meta: new Meta('test-model', 'test-id'),
            messages: new Collection,
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

    // --- Model Identifier Parsing ---

    public function test_parse_model_identifier_splits_on_first_colon(): void
    {
        $this->assertSame(['openrouter', 'anthropic/claude-4'], PromptData::parseModelIdentifier('openrouter:anthropic/claude-4'));
    }

    public function test_parse_model_identifier_handles_multiple_colons(): void
    {
        $this->assertSame(['openrouter', 'anthropic/claude:latest'], PromptData::parseModelIdentifier('openrouter:anthropic/claude:latest'));
    }

    public function test_parse_model_identifier_throws_without_colon(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("must be in 'provider:model' format");

        PromptData::parseModelIdentifier('no-colon-model');
    }

    // --- Tool Registration ---

    public function test_get_tools_returns_empty_array_by_default(): void
    {
        $service = app(AiService::class);

        $this->assertSame([], $service->getTools());
    }

    public function test_resolve_tools_using_registers_tools(): void
    {
        $service = app(AiService::class);

        $tool = Tool::as('test_tool')
            ->for('A test tool.')
            ->using(fn (): string => 'result');

        $service->resolveToolsUsing(fn (): array => [$tool]);

        $tools = $service->getTools();
        $this->assertCount(1, $tools);
    }

    public function test_tool_resolver_receives_context(): void
    {
        $service = app(AiService::class);
        $receivedContext = null;

        $service->resolveToolsUsing(function (array $context) use (&$receivedContext): array {
            $receivedContext = $context;

            return [];
        });

        $service->setContext(['customer' => 'test-customer']);
        $service->getTools();

        $this->assertSame(['customer' => 'test-customer'], $receivedContext);
    }

    public function test_set_context_and_get_context(): void
    {
        $service = app(AiService::class);

        $service->setContext(['key' => 'value']);

        $this->assertSame(['key' => 'value'], $service->getContext());
    }

    // --- sendMessages ---

    public function test_send_messages_returns_response(): void
    {
        Prism::fake([
            $this->makeTextResponse(),
        ]);

        $service = app(AiService::class);
        $response = $service->sendMessages(collect([new UserMessage('Hello')]), $this->makePrompt());

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame('Hello from AI', $response->text);
    }

    public function test_send_messages_includes_extra_context(): void
    {
        Prism::fake([
            $this->makeTextResponse('Response with context'),
        ]);

        $service = app(AiService::class);
        $extraContext = new PromptData(
            id: 'extra',
            model: 'openrouter:test-model',
            prompt: 'Extra context.',
        );

        $response = $service->sendMessages(
            collect([new UserMessage('Hello')]),
            $this->makePrompt(),
            $extraContext,
        );

        $this->assertSame('Response with context', $response->text);
    }

    // --- Finish Reason Handling ---

    public function test_finish_reason_stop_succeeds(): void
    {
        Prism::fake([
            $this->makeTextResponse(finishReason: FinishReason::Stop),
        ]);

        $service = app(AiService::class);
        $response = $service->sendMessages(collect([new UserMessage('Hello')]), $this->makePrompt());

        $this->assertSame(FinishReason::Stop, $response->finishReason);
    }

    public function test_finish_reason_tool_calls_succeeds(): void
    {
        Prism::fake([
            $this->makeTextResponse(finishReason: FinishReason::ToolCalls),
        ]);

        $service = app(AiService::class);
        $response = $service->sendMessages(collect([new UserMessage('Hello')]), $this->makePrompt());

        $this->assertSame(FinishReason::ToolCalls, $response->finishReason);
    }

    public function test_finish_reason_unknown_throws(): void
    {
        Prism::fake([
            $this->makeTextResponse(finishReason: FinishReason::Unknown),
        ]);

        $this->expectException(PrismException::class);
        $this->expectExceptionMessage('Unexpected AI finish reason: unknown');

        $service = app(AiService::class);
        $service->sendMessages(collect([new UserMessage('Hello')]), $this->makePrompt());
    }

    public function test_finish_reason_error_throws(): void
    {
        Prism::fake([
            $this->makeTextResponse(finishReason: FinishReason::Error),
        ]);

        $this->expectException(PrismException::class);
        $this->expectExceptionMessage('Unexpected AI finish reason: error');

        $service = app(AiService::class);
        $service->sendMessages(collect([new UserMessage('Hello')]), $this->makePrompt());
    }

    public function test_finish_reason_length_reports_but_returns(): void
    {
        Prism::fake([
            $this->makeTextResponse(finishReason: FinishReason::Length),
        ]);

        $service = app(AiService::class);
        $response = $service->sendMessages(collect([new UserMessage('Hello')]), $this->makePrompt());

        // Length finish reason is reported but the response is still returned.
        $this->assertSame(FinishReason::Length, $response->finishReason);
    }

    // --- sendStructuredMessages ---

    public function test_send_structured_messages_returns_response(): void
    {
        Prism::fake([
            StructuredResponseFake::make()
                ->withStructured(['answer' => 'test'])
                ->withFinishReason(FinishReason::Stop),
        ]);

        $service = app(AiService::class);
        $response = $service->sendStructuredMessages(
            collect([new UserMessage('Hello')]),
            $this->makePrompt(),
            $this->makeSchema(),
        );

        $this->assertInstanceOf(StructuredResponse::class, $response);
        $this->assertSame(['answer' => 'test'], $response->structured);
    }

    // --- sendStructuredMessagesWithTools ---

    public function test_send_structured_messages_with_tools_happy_path(): void
    {
        Prism::fake([
            // First call: text response with tools
            TextResponseFake::make()
                ->withText('The answer is 42')
                ->withMessages(collect([new AssistantMessage('The answer is 42')]))
                ->withFinishReason(FinishReason::Stop),
            // Second call: structured extraction
            StructuredResponseFake::make()
                ->withStructured(['answer' => '42'])
                ->withFinishReason(FinishReason::Stop),
        ]);

        $service = app(AiService::class);
        $response = $service->sendStructuredMessagesWithTools(
            collect([new UserMessage('What is the answer?')]),
            $this->makePrompt(),
            $this->makeSchema(),
        );

        $this->assertSame(['answer' => '42'], $response->structured);
    }

    public function test_send_structured_messages_with_tools_throws_when_no_assistant_message(): void
    {
        Prism::fake([
            // Text response with no messages
            TextResponseFake::make()
                ->withText('Hello')
                ->withMessages(collect([]))
                ->withFinishReason(FinishReason::Stop),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('text step did not produce an assistant message');

        $service = app(AiService::class);
        $service->sendStructuredMessagesWithTools(
            collect([new UserMessage('Hello')]),
            $this->makePrompt(),
            $this->makeSchema(),
        );
    }

    public function test_send_structured_messages_with_tools_throws_on_empty_assistant_content(): void
    {
        Prism::fake([
            TextResponseFake::make()
                ->withText('')
                ->withMessages(collect([new AssistantMessage('')]))
                ->withFinishReason(FinishReason::Stop),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('text step did not produce an assistant message');

        $service = app(AiService::class);
        $service->sendStructuredMessagesWithTools(
            collect([new UserMessage('Hello')]),
            $this->makePrompt(),
            $this->makeSchema(),
        );
    }

    // --- Retry Jitter ---

    public function test_retry_sleep_applies_jitter(): void
    {
        config()->set('ai-workflow.retry.jitter', true);

        $service = app(AiService::class);
        $method = new \ReflectionMethod($service, 'retrySleep');
        $closure = $method->invoke($service);

        $exception = new \Illuminate\Http\Client\RequestException(
            new \Illuminate\Http\Client\Response(
                new \GuzzleHttp\Psr7\Response(500)
            )
        );

        // Call multiple times — with jitter, values should vary
        $delays = [];
        for ($i = 0; $i < 20; $i++) {
            $delays[] = $closure(2, $exception);
        }

        // All delays should be in the jitter range: 2 * 2000 = 4000 ± 25% = 3000..5000
        foreach ($delays as $delay) {
            $this->assertGreaterThanOrEqual(3000, $delay);
            $this->assertLessThanOrEqual(5000, $delay);
        }

        // At least some variation should exist (extremely unlikely to all be the same)
        $this->assertGreaterThan(1, count(array_unique($delays)));
    }

    public function test_retry_sleep_no_jitter_when_disabled(): void
    {
        config()->set('ai-workflow.retry.jitter', false);

        $service = app(AiService::class);
        $method = new \ReflectionMethod($service, 'retrySleep');
        $closure = $method->invoke($service);

        $exception = new \Illuminate\Http\Client\RequestException(
            new \Illuminate\Http\Client\Response(
                new \GuzzleHttp\Psr7\Response(500)
            )
        );

        $delays = [];
        for ($i = 0; $i < 5; $i++) {
            $delays[] = $closure(2, $exception);
        }

        // Without jitter, all delays should be exactly 4000 (2 * 2000)
        foreach ($delays as $delay) {
            $this->assertSame(4000, $delay);
        }
    }
}
