<?php

declare(strict_types=1);

namespace AiWorkflow\Tests;

use AiWorkflow\AiService;
use AiWorkflow\Enums\GuardrailDirection;
use AiWorkflow\Exceptions\GuardrailViolationException;
use AiWorkflow\Middleware\AiWorkflowContext;
use AiWorkflow\Middleware\AiWorkflowMiddleware;
use AiWorkflow\Middleware\InputGuardrail;
use AiWorkflow\Middleware\OutputGuardrail;
use AiWorkflow\Tests\Concerns\MakesTestFixtures;
use Closure;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\StructuredResponseFake;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\Text\Response;
use Prism\Prism\ValueObjects\Messages\UserMessage;

class AiServiceMiddlewareTest extends TestCase
{
    use MakesTestFixtures;

    // --- Middleware Pipeline ---

    public function test_middleware_called_in_order(): void
    {
        Prism::fake([
            TextResponseFake::make()
                ->withText('Hello')
                ->withFinishReason(FinishReason::Stop),
        ]);

        $order = [];

        $first = new class($order) implements AiWorkflowMiddleware
        {
            /** @param list<string> $order */
            public function __construct(private array &$order) {}

            public function handle(AiWorkflowContext $context, Closure $next): AiWorkflowContext
            {
                $this->order[] = 'first:before';
                $context = $next($context);
                $this->order[] = 'first:after';

                return $context;
            }
        };

        $second = new class($order) implements AiWorkflowMiddleware
        {
            /** @param list<string> $order */
            public function __construct(private array &$order) {}

            public function handle(AiWorkflowContext $context, Closure $next): AiWorkflowContext
            {
                $this->order[] = 'second:before';
                $context = $next($context);
                $this->order[] = 'second:after';

                return $context;
            }
        };

        $service = app(AiService::class);
        $service->addMiddleware($first);
        $service->addMiddleware($second);

        $service->sendMessages(collect([new UserMessage('Hi')]), $this->makePrompt());

        $this->assertSame(['first:before', 'second:before', 'second:after', 'first:after'], $order);
    }

    public function test_middleware_can_modify_messages(): void
    {
        Prism::fake([
            TextResponseFake::make()
                ->withText('Modified response')
                ->withFinishReason(FinishReason::Stop),
        ]);

        $messageCount = 0;

        $injector = new class implements AiWorkflowMiddleware
        {
            public function handle(AiWorkflowContext $context, Closure $next): AiWorkflowContext
            {
                $context->messages[] = new UserMessage('Injected by middleware');

                return $next($context);
            }
        };

        $spy = new class($messageCount) implements AiWorkflowMiddleware
        {
            public function __construct(private int &$messageCount) {}

            public function handle(AiWorkflowContext $context, Closure $next): AiWorkflowContext
            {
                $this->messageCount = count($context->messages);

                return $next($context);
            }
        };

        $service = app(AiService::class);
        $service->addMiddleware($injector);
        $service->addMiddleware($spy);

        $response = $service->sendMessages(collect([new UserMessage('Hi')]), $this->makePrompt());

        $this->assertSame('Modified response', $response->text);
        $this->assertSame(2, $messageCount);
    }

    public function test_middleware_can_modify_system_prompt(): void
    {
        Prism::fake([
            TextResponseFake::make()
                ->withText('OK')
                ->withFinishReason(FinishReason::Stop),
        ]);

        $capturedPrompt = '';

        $modifier = new class implements AiWorkflowMiddleware
        {
            public function handle(AiWorkflowContext $context, Closure $next): AiWorkflowContext
            {
                $context->systemPrompt .= "\nAlways be concise.";

                return $next($context);
            }
        };

        $spy = new class($capturedPrompt) implements AiWorkflowMiddleware
        {
            public function __construct(private string &$capturedPrompt) {}

            public function handle(AiWorkflowContext $context, Closure $next): AiWorkflowContext
            {
                $this->capturedPrompt = $context->systemPrompt;

                return $next($context);
            }
        };

        $service = app(AiService::class);
        $service->addMiddleware($modifier);
        $service->addMiddleware($spy);

        $service->sendMessages(collect([new UserMessage('Hi')]), $this->makePrompt());

        $this->assertStringContainsString('Always be concise.', $capturedPrompt);
    }

    public function test_middleware_can_short_circuit(): void
    {
        // No Prism::fake — the real call should never happen.
        $middleware = new class implements AiWorkflowMiddleware
        {
            public function handle(AiWorkflowContext $context, Closure $next): AiWorkflowContext
            {
                $context->response = new Response(
                    steps: collect([]),
                    text: 'Short-circuited',
                    finishReason: FinishReason::Stop,
                    toolCalls: [],
                    toolResults: [],
                    usage: new \Prism\Prism\ValueObjects\Usage(0, 0),
                    meta: new \Prism\Prism\ValueObjects\Meta(id: '', model: 'test'),
                    messages: collect([]),
                );

                return $context;
            }
        };

        $service = app(AiService::class);
        $service->addMiddleware($middleware);

        $response = $service->sendMessages(collect([new UserMessage('Hi')]), $this->makePrompt());

        $this->assertSame('Short-circuited', $response->text);
    }

    public function test_middleware_can_inspect_response(): void
    {
        Prism::fake([
            TextResponseFake::make()
                ->withText('Original response')
                ->withFinishReason(FinishReason::Stop),
        ]);

        $capturedResponse = null;

        $middleware = new class($capturedResponse) implements AiWorkflowMiddleware
        {
            public function __construct(private ?Response &$captured) {}

            public function handle(AiWorkflowContext $context, Closure $next): AiWorkflowContext
            {
                $context = $next($context);

                if ($context->response instanceof Response) {
                    $this->captured = $context->response;
                }

                return $context;
            }
        };

        $service = app(AiService::class);
        $service->addMiddleware($middleware);

        $service->sendMessages(collect([new UserMessage('Hi')]), $this->makePrompt());

        $this->assertNotNull($capturedResponse);
        $this->assertSame('Original response', $capturedResponse->text);
    }

    public function test_middleware_applies_to_structured_messages(): void
    {
        Prism::fake([
            StructuredResponseFake::make()
                ->withStructured(['answer' => 'test'])
                ->withFinishReason(FinishReason::Stop),
        ]);

        $called = false;

        $capturedMethod = '';

        $middleware = new class($called, $capturedMethod) implements AiWorkflowMiddleware
        {
            public function __construct(private bool &$called, private string &$capturedMethod) {}

            public function handle(AiWorkflowContext $context, Closure $next): AiWorkflowContext
            {
                $this->called = true;
                $this->capturedMethod = $context->method;

                return $next($context);
            }
        };

        $service = app(AiService::class);
        $service->addMiddleware($middleware);

        $service->sendStructuredMessages(
            collect([new UserMessage('Hi')]),
            $this->makePrompt(),
            $this->makeSchema(),
        );

        $this->assertTrue($called);
        $this->assertSame('sendStructuredMessages', $capturedMethod);
    }

    public function test_clear_middleware_removes_all(): void
    {
        Prism::fake([
            TextResponseFake::make()
                ->withText('Hello')
                ->withFinishReason(FinishReason::Stop),
        ]);

        $called = false;

        $middleware = new class($called) implements AiWorkflowMiddleware
        {
            public function __construct(private bool &$called) {}

            public function handle(AiWorkflowContext $context, Closure $next): AiWorkflowContext
            {
                $this->called = true;

                return $next($context);
            }
        };

        $service = app(AiService::class);
        $service->addMiddleware($middleware);
        $service->clearMiddleware();

        $service->sendMessages(collect([new UserMessage('Hi')]), $this->makePrompt());

        $this->assertFalse($called);
    }

    public function test_global_middleware_resolved_from_config(): void
    {
        Prism::fake([
            TextResponseFake::make()
                ->withText('Hello')
                ->withFinishReason(FinishReason::Stop),
        ]);

        // Register a test middleware in the container
        $called = false;
        $testMiddleware = new class($called) implements AiWorkflowMiddleware
        {
            public function __construct(private bool &$called) {}

            public function handle(AiWorkflowContext $context, Closure $next): AiWorkflowContext
            {
                $this->called = true;

                return $next($context);
            }
        };

        $this->app->instance('test.global.middleware', $testMiddleware);
        config()->set('ai-workflow.middleware', ['test.global.middleware']);

        $service = app(AiService::class);
        $service->sendMessages(collect([new UserMessage('Hi')]), $this->makePrompt());

        $this->assertTrue($called);
    }

    // --- Input Guardrail ---

    public function test_input_guardrail_blocks_when_validation_fails(): void
    {
        $guardrail = new class extends InputGuardrail
        {
            protected function validate(AiWorkflowContext $context): void
            {
                throw new GuardrailViolationException('test-guardrail', GuardrailDirection::Input, 'Blocked by test');
            }
        };

        $service = app(AiService::class);
        $service->addMiddleware($guardrail);

        $this->expectException(GuardrailViolationException::class);
        $this->expectExceptionMessage('Blocked by test');

        $service->sendMessages(collect([new UserMessage('Hi')]), $this->makePrompt());
    }

    public function test_input_guardrail_passes_when_valid(): void
    {
        Prism::fake([
            TextResponseFake::make()
                ->withText('Allowed')
                ->withFinishReason(FinishReason::Stop),
        ]);

        $guardrail = new class extends InputGuardrail
        {
            protected function validate(AiWorkflowContext $context): void
            {
                // Validation passes — no exception thrown.
            }
        };

        $service = app(AiService::class);
        $service->addMiddleware($guardrail);

        $response = $service->sendMessages(collect([new UserMessage('Hi')]), $this->makePrompt());

        $this->assertSame('Allowed', $response->text);
    }

    // --- Output Guardrail ---

    public function test_output_guardrail_blocks_after_response(): void
    {
        Prism::fake([
            TextResponseFake::make()
                ->withText('Bad content')
                ->withFinishReason(FinishReason::Stop),
        ]);

        $guardrail = new class extends OutputGuardrail
        {
            protected function validate(AiWorkflowContext $context): void
            {
                if ($context->response instanceof Response && str_contains($context->response->text, 'Bad')) {
                    throw new GuardrailViolationException('content-filter', GuardrailDirection::Output, 'Response contains bad content');
                }
            }
        };

        $service = app(AiService::class);
        $service->addMiddleware($guardrail);

        $this->expectException(GuardrailViolationException::class);
        $this->expectExceptionMessage('Response contains bad content');

        $service->sendMessages(collect([new UserMessage('Hi')]), $this->makePrompt());
    }

    public function test_output_guardrail_passes_when_valid(): void
    {
        Prism::fake([
            TextResponseFake::make()
                ->withText('Good content')
                ->withFinishReason(FinishReason::Stop),
        ]);

        $guardrail = new class extends OutputGuardrail
        {
            protected function validate(AiWorkflowContext $context): void
            {
                // Validation passes.
            }
        };

        $service = app(AiService::class);
        $service->addMiddleware($guardrail);

        $response = $service->sendMessages(collect([new UserMessage('Hi')]), $this->makePrompt());

        $this->assertSame('Good content', $response->text);
    }

    // --- GuardrailViolationException ---

    public function test_guardrail_exception_has_properties(): void
    {
        $exception = new GuardrailViolationException('pii-detection', GuardrailDirection::Input, 'PII detected');

        $this->assertSame('pii-detection', $exception->guardrail);
        $this->assertSame(GuardrailDirection::Input, $exception->direction);
        $this->assertSame('PII detected', $exception->getMessage());
    }

    public function test_guardrail_exception_default_message(): void
    {
        $exception = new GuardrailViolationException('content-filter', GuardrailDirection::Output);

        $this->assertSame("Guardrail 'content-filter' violated (output)", $exception->getMessage());
    }
}
