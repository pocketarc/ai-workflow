<?php

declare(strict_types=1);

namespace AiWorkflow;

use AiWorkflow\Events\AiWorkflowRequestCompleted;
use AiWorkflow\Events\AiWorkflowRequestFailed;
use AiWorkflow\Exceptions\RetriesExhaustedException;
use AiWorkflow\Exceptions\StructuredValidationException;
use AiWorkflow\Middleware\AiWorkflowContext;
use AiWorkflow\Middleware\AiWorkflowMiddleware;
use AiWorkflow\Models\AiWorkflowExecution;
use AiWorkflow\Models\AiWorkflowRequest;
use Closure;
use Generator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Contracts\Message;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Exceptions\PrismStructuredDecodingException;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\StreamEvent;
use Prism\Prism\Structured\Response as StructuredResponse;
use Prism\Prism\Text\Response;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;
use Throwable;

class AiService
{
    /** @var array<string, mixed> */
    private array $context = [];

    /** @var list<string> */
    private array $tags = [];

    /** @var list<AiWorkflowMiddleware> */
    private array $middleware = [];

    /** @var (Closure(array<string, mixed>): list<Tool>)|null */
    private ?Closure $toolResolver = null;

    private ?AiWorkflowExecution $currentExecution = null;

    public function __construct(
        private readonly AiWorkflowCache $cache,
    ) {}

    /**
     * Set arbitrary context data that tool resolvers can access.
     *
     * @param  array<string, mixed>  $context
     */
    public function setContext(array $context): void
    {
        $this->context = $context;
    }

    /**
     * @return array<string, mixed>
     */
    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * Set tags to attach to subsequent AI requests.
     *
     * @param  list<string>  $tags
     */
    public function setTags(array $tags): void
    {
        $this->tags = $tags;
    }

    /**
     * @return list<string>
     */
    public function getTags(): array
    {
        return $this->tags;
    }

    /**
     * Add a middleware to the instance pipeline.
     */
    public function addMiddleware(AiWorkflowMiddleware $middleware): void
    {
        $this->middleware[] = $middleware;
    }

    /**
     * Remove all instance-level middleware.
     */
    public function clearMiddleware(): void
    {
        $this->middleware = [];
    }

    /**
     * Register a callback that returns the tools available for text requests.
     *
     * @param  Closure(array<string, mixed>): list<Tool>  $resolver
     */
    public function resolveToolsUsing(Closure $resolver): void
    {
        $this->toolResolver = $resolver;
    }

    /**
     * @return list<Tool>
     */
    public function getTools(): array
    {
        if ($this->toolResolver === null) {
            return [];
        }

        return ($this->toolResolver)($this->context);
    }

    /**
     * Start a named execution to group subsequent AI calls.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function startExecution(string $name, array $metadata = []): void
    {
        if ($this->isLoggingEnabled()) {
            $this->currentExecution = AiWorkflowExecution::create([
                'name' => $name,
                'metadata' => $metadata !== [] ? $metadata : null,
            ]);
        }
    }

    /**
     * End the current execution and return it.
     */
    public function endExecution(): ?AiWorkflowExecution
    {
        $execution = $this->currentExecution;
        $this->currentExecution = null;

        return $execution;
    }

    /**
     * Send messages to the AI with conversation history and tools.
     *
     * @param  Collection<int, Message>  $messages
     */
    public function sendMessages(
        Collection $messages,
        PromptData $prompt,
        ?PromptData $extraContext = null,
        int $steps = 15,
    ): Response {
        [$provider, $model] = PromptData::parseModelIdentifier($prompt->model);
        $extraPrompt = $extraContext !== null ? $extraContext->prompt : '';
        $systemPrompt = trim($extraPrompt."\n\n".$prompt->prompt);
        $retryAttempts = 0;

        $cached = $this->getCachedTextResponse($provider, $model, $systemPrompt, $messages->all(), $prompt);
        if ($cached !== null) {
            return $cached;
        }

        /** @var array{text: int, structured: int} $maxTokens */
        $maxTokens = config('ai-workflow.max_tokens');

        /** @var array<string, mixed> $clientOptions */
        $clientOptions = config('ai-workflow.client_options');

        $context = new AiWorkflowContext(
            messages: array_values($messages->all()),
            prompt: $prompt,
            systemPrompt: $systemPrompt,
            method: 'sendMessages',
        );

        $startTime = microtime(true);

        try {
            $context = $this->runThroughMiddleware($context, function (AiWorkflowContext $ctx) use ($provider, $model, &$retryAttempts, $steps, $maxTokens, $clientOptions): AiWorkflowContext {
                $ctx->response = Prism::text()
                    ->using($provider, $model)
                    ->withSystemPrompt($ctx->systemPrompt)
                    ->withMessages($ctx->messages)
                    ->withTools($this->getTools())
                    ->withMaxSteps($steps)
                    ->withMaxTokens($maxTokens['text'])
                    ->withClientOptions($clientOptions)
                    ->withClientRetry(
                        times: $this->retryTimes(),
                        sleepMilliseconds: $this->retrySleep($retryAttempts),
                        when: $this->retryWhen(),
                    )
                    ->asText();

                return $ctx;
            });

            /** @var Response $response */
            $response = $context->response;
            $durationMs = (microtime(true) - $startTime) * 1000;

            $this->logUnexpectedFinishReason($response->finishReason, $prompt, 'sendMessages');
            $this->logRequest($prompt, 'sendMessages', $provider, $model, $context->systemPrompt, $context->messages, $durationMs, textResponse: $response);
            $this->dispatchCompletedEvent($prompt, 'sendMessages', $model, $response->finishReason, $response->usage, $durationMs);
            $this->cacheTextResponse($provider, $model, $context->systemPrompt, $context->messages, $prompt, $response);

            return $response;
        } catch (Throwable $exception) {
            $durationMs = (microtime(true) - $startTime) * 1000;
            $this->logRequest($prompt, 'sendMessages', $provider, $model, $context->systemPrompt, $context->messages, $durationMs, error: $exception);
            $this->dispatchFailedEvent($prompt, 'sendMessages', $model, $exception, $durationMs);

            if ($retryAttempts > 0) {
                throw new RetriesExhaustedException($retryAttempts, $exception);
            }
            throw $exception;
        }
    }

    /**
     * Send messages to the AI and get structured output.
     *
     * @param  Collection<int, Message>  $messages
     * @param  string|null  $modelOverride  Optional model identifier to use instead of the prompt's model (must be in provider:model format).
     */
    public function sendStructuredMessages(
        Collection $messages,
        PromptData $prompt,
        ObjectSchema $schema,
        ?string $modelOverride = null,
    ): StructuredResponse {
        $effectiveModelIdentifier = $modelOverride ?? $prompt->model;
        [$provider, $model] = PromptData::parseModelIdentifier($effectiveModelIdentifier);

        $cached = $this->getCachedStructuredResponse($provider, $model, $prompt->prompt, $messages->all(), $prompt, $schema);
        if ($cached !== null) {
            return $cached;
        }

        $context = new AiWorkflowContext(
            messages: array_values($messages->all()),
            prompt: $prompt,
            systemPrompt: $prompt->prompt,
            method: 'sendStructuredMessages',
            schema: $schema,
        );

        $startTime = microtime(true);

        try {
            $context = $this->runThroughMiddleware($context, function (AiWorkflowContext $ctx) use ($schema, $effectiveModelIdentifier): AiWorkflowContext {
                $ctx->response = $this->executeStructuredRequest(
                    new Collection($ctx->messages),
                    $ctx->prompt,
                    $schema,
                    $effectiveModelIdentifier,
                    $ctx->systemPrompt,
                );

                return $ctx;
            });

            /** @var StructuredResponse $response */
            $response = $context->response;
            $durationMs = (microtime(true) - $startTime) * 1000;

            $this->logUnexpectedFinishReason($response->finishReason, $prompt, 'sendStructuredMessages');
            $this->logRequest($prompt, 'sendStructuredMessages', $provider, $model, $context->systemPrompt, $context->messages, $durationMs, structuredResponse: $response, schema: $schema);
            $this->dispatchCompletedEvent($prompt, 'sendStructuredMessages', $model, $response->finishReason, $response->usage, $durationMs);
            $this->cacheStructuredResponse($provider, $model, $context->systemPrompt, $context->messages, $prompt, $schema, $response);

            return $response;
        } catch (PrismStructuredDecodingException $decodingException) {
            if ($modelOverride === null && $prompt->fallbackModel !== null) {
                Log::warning('AiWorkflow: Structured decoding failed, switching to fallback model', [
                    'prompt_id' => $prompt->id,
                    'primary_model' => $model,
                    'fallback_model' => $prompt->fallbackModel,
                ]);

                [$fallbackProvider, $fallbackModel] = PromptData::parseModelIdentifier($prompt->fallbackModel);

                $fallbackStartTime = microtime(true);
                $response = $this->executeStructuredRequest($messages, $prompt, $schema, $prompt->fallbackModel);
                $fallbackDurationMs = (microtime(true) - $fallbackStartTime) * 1000;

                $this->logUnexpectedFinishReason($response->finishReason, $prompt, 'sendStructuredMessages');
                $this->logRequest($prompt, 'sendStructuredMessages', $fallbackProvider, $fallbackModel, $prompt->prompt, $messages->all(), $fallbackDurationMs, structuredResponse: $response, schema: $schema);
                $this->dispatchCompletedEvent($prompt, 'sendStructuredMessages', $fallbackModel, $response->finishReason, $response->usage, $fallbackDurationMs);

                return $response;
            }

            $durationMs = (microtime(true) - $startTime) * 1000;
            $this->logRequest($prompt, 'sendStructuredMessages', $provider, $model, $prompt->prompt, $messages->all(), $durationMs, error: $decodingException, schema: $schema);
            $this->dispatchFailedEvent($prompt, 'sendStructuredMessages', $model, $decodingException, $durationMs);

            throw $decodingException;
        } catch (Throwable $exception) {
            $durationMs = (microtime(true) - $startTime) * 1000;
            $this->logRequest($prompt, 'sendStructuredMessages', $provider, $model, $prompt->prompt, $messages->all(), $durationMs, error: $exception, schema: $schema);
            $this->dispatchFailedEvent($prompt, 'sendStructuredMessages', $model, $exception, $durationMs);

            throw $exception;
        }
    }

    /**
     * Send messages with tool-calling first, then extract structured output.
     *
     * The text step is logged via sendMessages(). This method only logs the structured extraction step.
     *
     * @param  Collection<int, Message>  $messages
     */
    public function sendStructuredMessagesWithTools(
        Collection $messages,
        PromptData $prompt,
        ObjectSchema $schema,
    ): StructuredResponse {
        $schemaJson = json_encode($schema->toArray(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $message = "Look at the following structure to see what's expected to be retrieved:\n\n".$schemaJson;

        /** @var Collection<int, Message> $tempMessages */
        $tempMessages = collect($messages->all());
        $tempMessages->push(new UserMessage($message));
        $textResponse = $this->sendMessages($tempMessages, $prompt);

        $lastMessage = $textResponse->messages->last();
        if (! $lastMessage instanceof AssistantMessage || $lastMessage->content === '') {
            throw new \RuntimeException('sendStructuredMessagesWithTools: text step did not produce an assistant message');
        }

        /** @var list<Message> $messageList */
        $messageList = [
            new UserMessage(
                $lastMessage->content.
                "\n\n---------\nLook at the above response and then use that as context for you to generate your response in the provided JSON schema."
            ),
        ];
        $newMessages = new Collection($messageList);

        [$provider, $model] = PromptData::parseModelIdentifier($prompt->model);
        $startTime = microtime(true);

        try {
            $response = $this->executeStructuredRequestWithoutSystemPrompt($schema, $newMessages, $prompt->model, $prompt);
            $durationMs = (microtime(true) - $startTime) * 1000;

            $this->logUnexpectedFinishReason($response->finishReason, $prompt, 'sendStructuredMessagesWithTools');
            $this->logRequest($prompt, 'sendStructuredMessagesWithTools', $provider, $model, '', $newMessages->all(), $durationMs, structuredResponse: $response, schema: $schema);
            $this->dispatchCompletedEvent($prompt, 'sendStructuredMessagesWithTools', $model, $response->finishReason, $response->usage, $durationMs);

            return $response;
        } catch (PrismStructuredDecodingException $decodingException) {
            if ($prompt->fallbackModel !== null) {
                Log::warning('AiWorkflow: Structured decoding failed, switching to fallback model', [
                    'prompt_id' => $prompt->id,
                    'primary_model' => $model,
                    'fallback_model' => $prompt->fallbackModel,
                ]);

                [$fallbackProvider, $fallbackModel] = PromptData::parseModelIdentifier($prompt->fallbackModel);

                $fallbackStartTime = microtime(true);
                $response = $this->executeStructuredRequestWithoutSystemPrompt($schema, $newMessages, $prompt->fallbackModel, $prompt);
                $fallbackDurationMs = (microtime(true) - $fallbackStartTime) * 1000;

                $this->logUnexpectedFinishReason($response->finishReason, $prompt, 'sendStructuredMessagesWithTools');
                $this->logRequest($prompt, 'sendStructuredMessagesWithTools', $fallbackProvider, $fallbackModel, '', $newMessages->all(), $fallbackDurationMs, structuredResponse: $response, schema: $schema);
                $this->dispatchCompletedEvent($prompt, 'sendStructuredMessagesWithTools', $fallbackModel, $response->finishReason, $response->usage, $fallbackDurationMs);

                return $response;
            }

            $durationMs = (microtime(true) - $startTime) * 1000;
            $this->logRequest($prompt, 'sendStructuredMessagesWithTools', $provider, $model, '', $newMessages->all(), $durationMs, error: $decodingException, schema: $schema);
            $this->dispatchFailedEvent($prompt, 'sendStructuredMessagesWithTools', $model, $decodingException, $durationMs);

            throw $decodingException;
        } catch (Throwable $exception) {
            $durationMs = (microtime(true) - $startTime) * 1000;
            $this->logRequest($prompt, 'sendStructuredMessagesWithTools', $provider, $model, '', $newMessages->all(), $durationMs, error: $exception, schema: $schema);
            $this->dispatchFailedEvent($prompt, 'sendStructuredMessagesWithTools', $model, $exception, $durationMs);

            throw $exception;
        }
    }

    /**
     * Send structured messages and return a validated Laravel Data instance.
     *
     * Generates the schema from the Data class, sends the request, validates
     * the response, and retries with feedback on validation failure.
     *
     * @template T of \Spatie\LaravelData\Data
     *
     * @param  Collection<int, Message>  $messages
     * @param  class-string<T>  $dataClass
     * @return T
     */
    public function sendStructuredData(
        Collection $messages,
        PromptData $prompt,
        string $dataClass,
        int $maxAttempts = 3,
    ): \Spatie\LaravelData\Data {
        if (! class_exists(\Spatie\LaravelData\Data::class)) {
            throw new \RuntimeException('spatie/laravel-data is required to use sendStructuredData(). Install it with: composer require spatie/laravel-data');
        }

        $schema = SchemaBuilder::fromDataClass($dataClass);

        /** @var Collection<int, Message> $attemptMessages */
        $attemptMessages = new Collection($messages->all());

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $response = $this->sendStructuredMessages($attemptMessages, $prompt, $schema);

            try {
                /** @var T */
                return $dataClass::from($response->structured);
            } catch (\Throwable $e) {
                if ($attempt === $maxAttempts) {
                    throw new StructuredValidationException($e->getMessage(), $attempt, $e);
                }

                $attemptMessages = new Collection([
                    ...$messages->all(),
                    new AssistantMessage(json_encode($response->structured, JSON_THROW_ON_ERROR)),
                    new UserMessage("The previous response failed validation: {$e->getMessage()}. Please fix the response and try again."),
                ]);
            }
        }

        throw new StructuredValidationException('Max attempts reached', $maxAttempts);
    }

    /**
     * Stream messages from the AI as a generator of events.
     *
     * Unlike sendMessages(), streaming does not support automatic retries.
     *
     * @param  Collection<int, Message>  $messages
     * @return Generator<int, StreamEvent, mixed, void>
     */
    public function streamMessages(
        Collection $messages,
        PromptData $prompt,
        ?PromptData $extraContext = null,
        int $steps = 15,
    ): Generator {
        [$provider, $model] = PromptData::parseModelIdentifier($prompt->model);
        $extraPrompt = $extraContext !== null ? $extraContext->prompt : '';
        $systemPrompt = trim($extraPrompt."\n\n".$prompt->prompt);

        /** @var array{text: int, structured: int} $maxTokens */
        $maxTokens = config('ai-workflow.max_tokens');

        /** @var array<string, mixed> $clientOptions */
        $clientOptions = config('ai-workflow.client_options');

        $startTime = microtime(true);

        $stream = Prism::text()
            ->using($provider, $model)
            ->withSystemPrompt($systemPrompt)
            ->withMessages($messages->all())
            ->withTools($this->getTools())
            ->withMaxSteps($steps)
            ->withMaxTokens($maxTokens['text'])
            ->withClientOptions($clientOptions)
            ->asStream();

        foreach ($stream as $event) {
            yield $event;

            if ($event instanceof StreamEndEvent) {
                $durationMs = (microtime(true) - $startTime) * 1000;

                $this->logUnexpectedFinishReason($event->finishReason, $prompt, 'streamMessages');
                $this->logStreamRequest($prompt, $provider, $model, $systemPrompt, $messages->all(), $event, $durationMs);
                $this->dispatchCompletedEvent($prompt, 'streamMessages', $model, $event->finishReason, $event->usage ?? new Usage(0, 0), $durationMs);
            }
        }
    }

    /**
     * Execute a structured Prism request with retry logic.
     *
     * @param  Collection<int, Message>  $messages
     */
    private function executeStructuredRequest(
        Collection $messages,
        PromptData $prompt,
        ObjectSchema $schema,
        string $modelIdentifier,
        ?string $systemPrompt = null,
    ): StructuredResponse {
        [$provider, $model] = PromptData::parseModelIdentifier($modelIdentifier);

        /** @var array{text: int, structured: int} $maxTokens */
        $maxTokens = config('ai-workflow.max_tokens');

        /** @var array<string, mixed> $clientOptions */
        $clientOptions = config('ai-workflow.client_options');

        return Prism::structured()
            ->using($provider, $model)
            ->withSystemPrompt($systemPrompt ?? $prompt->prompt)
            ->withSchema($schema)
            ->withMessages($messages->all())
            ->withMaxTokens($maxTokens['structured'])
            ->withClientOptions($clientOptions)
            ->withClientRetry(
                times: $this->retryTimes(),
                sleepMilliseconds: $this->retrySleep(),
                when: $this->retryWhen(),
            )
            ->asStructured();
    }

    /**
     * Execute a structured Prism request without system prompt.
     *
     * Used by sendStructuredMessagesWithTools() where the second step is purely
     * "parse this text into JSON" — the domain-specific system prompt would
     * add noise and token cost without helping the structured extraction.
     *
     * @param  Collection<int, Message>  $messages
     */
    private function executeStructuredRequestWithoutSystemPrompt(
        ObjectSchema $schema,
        Collection $messages,
        string $modelIdentifier,
        PromptData $prompt,
    ): StructuredResponse {
        [$provider, $model] = PromptData::parseModelIdentifier($modelIdentifier);

        /** @var array{text: int, structured: int} $maxTokens */
        $maxTokens = config('ai-workflow.max_tokens');

        /** @var array<string, mixed> $clientOptions */
        $clientOptions = config('ai-workflow.client_options');

        return Prism::structured()
            ->using($provider, $model)
            ->withSchema($schema)
            ->withMessages($messages->all())
            ->withMaxTokens($maxTokens['structured'])
            ->withClientOptions($clientOptions)
            ->withClientRetry(
                times: $this->retryTimes(),
                sleepMilliseconds: $this->retrySleep(),
                when: $this->retryWhen(),
            )
            ->asStructured();
    }

    /**
     * Run the context through the middleware pipeline, with the core handler as the innermost step.
     *
     * @param  Closure(AiWorkflowContext): AiWorkflowContext  $core
     */
    private function runThroughMiddleware(AiWorkflowContext $context, Closure $core): AiWorkflowContext
    {
        $middleware = $this->resolveMiddleware();

        if ($middleware === []) {
            return $core($context);
        }

        /** @var AiWorkflowContext */
        return app(Pipeline::class)
            ->send($context)
            ->through($middleware)
            ->then($core);
    }

    /**
     * Build the ordered middleware list from global config + instance middleware.
     *
     * @return list<AiWorkflowMiddleware>
     */
    private function resolveMiddleware(): array
    {
        /** @var list<class-string<AiWorkflowMiddleware>> $global */
        $global = config('ai-workflow.middleware', []);

        $resolved = [];
        foreach ($global as $className) {
            /** @var AiWorkflowMiddleware $instance */
            $instance = app($className);
            $resolved[] = $instance;
        }

        return [...$resolved, ...$this->middleware];
    }

    /**
     * Get the configured retry count.
     */
    private function retryTimes(): int
    {
        /** @var array{times: int, rate_limit_delay_ms: int, server_error_multiplier_ms: int, default_multiplier_ms: int, jitter: bool} $retryConfig */
        $retryConfig = config('ai-workflow.retry');

        return $retryConfig['times'];
    }

    /**
     * Build the retry sleep closure with optional jitter.
     */
    private function retrySleep(?int &$retryAttempts = null): Closure
    {
        /** @var array{times: int, rate_limit_delay_ms: int, server_error_multiplier_ms: int, default_multiplier_ms: int, jitter: bool} $retryConfig */
        $retryConfig = config('ai-workflow.retry');

        return function (int $attempt, Throwable $exception) use (&$retryAttempts, $retryConfig): int {
            if ($retryAttempts !== null) {
                $retryAttempts = $attempt;
            }

            $delay = $attempt * $retryConfig['default_multiplier_ms'];

            if ($exception instanceof RequestException) {
                $status = $exception->response->status();
                if ($status === 429) {
                    $delay = $retryConfig['rate_limit_delay_ms'];
                } elseif ($status >= 500 && $status < 600) {
                    $delay = $attempt * $retryConfig['server_error_multiplier_ms'];
                }
            }

            if ($retryConfig['jitter']) {
                $delay = $this->applyJitter($delay);
            }

            return $delay;
        };
    }

    /**
     * Apply ±25% random jitter to a delay value.
     */
    private function applyJitter(int $delay): int
    {
        if ($delay <= 0) {
            return 0;
        }

        $jitter = (int) ($delay * 0.25);

        return max(0, $delay + random_int(-$jitter, $jitter));
    }

    /**
     * Build the retry condition closure.
     */
    private function retryWhen(): Closure
    {
        return function (Throwable $exception): bool {
            if ($exception instanceof ConnectionException) {
                return true;
            }
            if ($exception instanceof RequestException) {
                $status = $exception->response->status();

                return $status === 429 || ($status >= 500 && $status < 600);
            }

            return false;
        };
    }

    /**
     * Log unexpected finish reasons — throw on transient issues, report on degraded responses.
     */
    private function logUnexpectedFinishReason(FinishReason $finishReason, PromptData $prompt, string $method): void
    {
        if ($finishReason === FinishReason::Stop || $finishReason === FinishReason::ToolCalls) {
            return;
        }

        $message = "Unexpected AI finish reason: {$finishReason->value} in {$method} using prompt {$prompt->id}";

        // Transient provider issues — throw to let callers skip gracefully.
        if (in_array($finishReason, [FinishReason::Unknown, FinishReason::Error, FinishReason::Other], true)) {
            throw new PrismException($message);
        }

        // Length/ContentFilter — worth monitoring but response may still be usable.
        report(new \RuntimeException($message));
    }

    private function isLoggingEnabled(): bool
    {
        /** @var array{enabled: bool} $loggingConfig */
        $loggingConfig = config('ai-workflow.logging');

        return $loggingConfig['enabled'];
    }

    /**
     * Merge prompt-level tags with service-level tags, deduplicated.
     *
     * @return list<string>|null
     */
    private function resolveTags(PromptData $prompt): ?array
    {
        $merged = array_values(array_unique([...$prompt->tags, ...$this->tags]));

        return $merged !== [] ? $merged : null;
    }

    /**
     * Log a request to the database if logging is enabled.
     *
     * @param  array<int, Message>  $messages
     */
    private function logRequest(
        PromptData $prompt,
        string $method,
        string $provider,
        string $model,
        string $systemPrompt,
        array $messages,
        float $durationMs,
        ?Response $textResponse = null,
        ?StructuredResponse $structuredResponse = null,
        ?ObjectSchema $schema = null,
        ?Throwable $error = null,
    ): void {
        if (! $this->isLoggingEnabled()) {
            return;
        }

        AiWorkflowRequest::create([
            'execution_id' => $this->currentExecution?->id,
            'prompt_id' => $prompt->id,
            'method' => $method,
            'provider' => $provider,
            'model' => $model,
            'system_prompt' => $systemPrompt,
            'messages' => MessageSerializer::serialize($messages),
            'response_text' => $textResponse?->text,
            'structured_response' => $structuredResponse?->structured,
            'finish_reason' => $textResponse?->finishReason->value ?? $structuredResponse?->finishReason->value,
            'input_tokens' => $textResponse?->usage->promptTokens ?? $structuredResponse?->usage->promptTokens,
            'output_tokens' => $textResponse?->usage->completionTokens ?? $structuredResponse?->usage->completionTokens,
            'duration_ms' => (int) $durationMs,
            'schema' => $schema?->toArray(),
            'error' => $error?->getMessage(),
            'tags' => $this->resolveTags($prompt),
        ]);
    }

    /**
     * Log a streaming request using the StreamEndEvent data.
     *
     * @param  array<int, Message>  $messages
     */
    private function logStreamRequest(
        PromptData $prompt,
        string $provider,
        string $model,
        string $systemPrompt,
        array $messages,
        StreamEndEvent $endEvent,
        float $durationMs,
    ): void {
        if (! $this->isLoggingEnabled()) {
            return;
        }

        AiWorkflowRequest::create([
            'execution_id' => $this->currentExecution?->id,
            'prompt_id' => $prompt->id,
            'method' => 'streamMessages',
            'provider' => $provider,
            'model' => $model,
            'system_prompt' => $systemPrompt,
            'messages' => MessageSerializer::serialize($messages),
            'response_text' => null,
            'finish_reason' => $endEvent->finishReason->value,
            'input_tokens' => $endEvent->usage?->promptTokens,
            'output_tokens' => $endEvent->usage?->completionTokens,
            'duration_ms' => (int) $durationMs,
            'tags' => $this->resolveTags($prompt),
        ]);
    }

    /**
     * @param  array<int, Message>  $messages
     */
    private function getCachedTextResponse(string $provider, string $model, string $systemPrompt, array $messages, PromptData $prompt): ?Response
    {
        if ($prompt->cacheTtl === null || ! $this->cache->isEnabled()) {
            return null;
        }

        $key = $this->cache->generateKey($provider, $model, $systemPrompt, $messages);
        $data = $this->cache->get($key);
        if ($data === null) {
            return null;
        }

        $text = is_string($data['text'] ?? null) ? $data['text'] : '';
        $finishReason = is_string($data['finish_reason'] ?? null) ? FinishReason::from($data['finish_reason']) : FinishReason::Stop;

        /** @var Collection<int, \Prism\Prism\Text\Step> $steps */
        $steps = collect([]);

        /** @var Collection<int, Message> $messages */
        $messages = collect([]);

        return new Response(
            steps: $steps,
            text: $text,
            finishReason: $finishReason,
            toolCalls: [],
            toolResults: [],
            usage: new Usage(0, 0),
            meta: new Meta(id: '', model: $model),
            messages: $messages,
        );
    }

    /**
     * @param  array<int, Message>  $messages
     */
    private function cacheTextResponse(string $provider, string $model, string $systemPrompt, array $messages, PromptData $prompt, Response $response): void
    {
        if ($prompt->cacheTtl === null || ! $this->cache->isEnabled()) {
            return;
        }

        $key = $this->cache->generateKey($provider, $model, $systemPrompt, $messages);
        $this->cache->put($key, [
            'text' => $response->text,
            'finish_reason' => $response->finishReason->value,
        ], $prompt->cacheTtl);
    }

    /**
     * @param  array<int, Message>  $messages
     */
    private function getCachedStructuredResponse(string $provider, string $model, string $systemPrompt, array $messages, PromptData $prompt, ObjectSchema $schema): ?StructuredResponse
    {
        if ($prompt->cacheTtl === null || ! $this->cache->isEnabled()) {
            return null;
        }

        $key = $this->cache->generateKey($provider, $model, $systemPrompt, $messages, $schema);
        $data = $this->cache->get($key);
        if ($data === null) {
            return null;
        }

        /** @var array<string, mixed> $structured */
        $structured = is_array($data['structured'] ?? null) ? $data['structured'] : [];
        $finishReason = is_string($data['finish_reason'] ?? null) ? FinishReason::from($data['finish_reason']) : FinishReason::Stop;

        /** @var Collection<int, \Prism\Prism\Structured\Step> $steps */
        $steps = collect([]);

        return new StructuredResponse(
            steps: $steps,
            text: json_encode($structured, JSON_THROW_ON_ERROR),
            structured: $structured,
            finishReason: $finishReason,
            usage: new Usage(0, 0),
            meta: new Meta(id: '', model: $model),
        );
    }

    /**
     * @param  array<int, Message>  $messages
     */
    private function cacheStructuredResponse(string $provider, string $model, string $systemPrompt, array $messages, PromptData $prompt, ObjectSchema $schema, StructuredResponse $response): void
    {
        if ($prompt->cacheTtl === null || ! $this->cache->isEnabled()) {
            return;
        }

        $key = $this->cache->generateKey($provider, $model, $systemPrompt, $messages, $schema);
        $this->cache->put($key, [
            'structured' => $response->structured,
            'finish_reason' => $response->finishReason->value,
        ], $prompt->cacheTtl);
    }

    private function dispatchCompletedEvent(
        PromptData $prompt,
        string $method,
        string $model,
        FinishReason $finishReason,
        Usage $usage,
        float $durationMs,
    ): void {
        AiWorkflowRequestCompleted::dispatch(
            $prompt,
            $method,
            $model,
            $finishReason,
            $usage,
            $durationMs,
            $this->currentExecution?->id,
        );
    }

    private function dispatchFailedEvent(
        PromptData $prompt,
        string $method,
        string $model,
        Throwable $exception,
        float $durationMs,
    ): void {
        AiWorkflowRequestFailed::dispatch(
            $prompt,
            $method,
            $model,
            $exception,
            $durationMs,
            $this->currentExecution?->id,
        );
    }
}
