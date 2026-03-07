<?php

declare(strict_types=1);

namespace AiWorkflow;

use AiWorkflow\Models\AiWorkflowExecution;
use AiWorkflow\Models\AiWorkflowRequest;
use Prism\Prism\Contracts\Message;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\ArraySchema;
use Prism\Prism\Schema\BooleanSchema;
use Prism\Prism\Schema\EnumSchema;
use Prism\Prism\Schema\NumberSchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Structured\Response as StructuredResponse;
use Prism\Prism\Text\Response;

class AiWorkflowReplayer
{
    public function __construct(
        private readonly PromptService $promptService,
    ) {}

    /**
     * Replay a recorded request with optional overrides.
     *
     * @param  string|null  $model  Model override in provider:model format.
     */
    public function replay(
        AiWorkflowRequest $request,
        bool $useCurrentPrompts = false,
        ?string $model = null,
    ): Response|StructuredResponse {
        $systemPrompt = $request->system_prompt;
        $replayProvider = $request->provider;
        $replayModel = $request->model;

        if ($useCurrentPrompts) {
            /** @var array<string, mixed> $templateVariables */
            $templateVariables = $request->template_variables ?? [];
            $prompt = $this->promptService->load($request->prompt_id, $templateVariables);
            $systemPrompt = $prompt->prompt;

            if ($model === null) {
                [$replayProvider, $replayModel] = PromptData::parseModelIdentifier($prompt->model);
            }
        }

        if ($model !== null) {
            [$replayProvider, $replayModel] = PromptData::parseModelIdentifier($model);
        }

        /** @var list<array<string, mixed>> $storedMessages */
        $storedMessages = $request->messages;
        $messages = MessageSerializer::deserialize($storedMessages);

        /** @var array<string, mixed> $clientOptions */
        $clientOptions = config('ai-workflow.client_options');

        return match ($request->method) {
            'sendStructuredMessages', 'sendStructuredMessagesWithTools' => $this->replayStructured(
                $replayProvider, $replayModel, $systemPrompt, $messages, $request, $clientOptions,
            ),
            default => $this->replayText(
                $replayProvider, $replayModel, $systemPrompt, $messages, $clientOptions,
            ),
        };
    }

    /**
     * Replay a request across multiple models for comparison.
     *
     * @param  list<string>  $models  Each model in provider:model format.
     * @return array<string, Response|StructuredResponse>
     */
    public function replayAcrossModels(
        AiWorkflowRequest $request,
        array $models,
        bool $useCurrentPrompts = false,
    ): array {
        $results = [];

        foreach ($models as $model) {
            $results[$model] = $this->replay($request, $useCurrentPrompts, $model);
        }

        return $results;
    }

    /**
     * Replay all requests in an execution, in order.
     *
     * @param  string|null  $model  Model override in provider:model format.
     * @return list<Response|StructuredResponse>
     */
    public function replayExecution(
        AiWorkflowExecution $execution,
        bool $useCurrentPrompts = false,
        ?string $model = null,
    ): array {
        /** @var list<AiWorkflowRequest> $requests */
        $requests = AiWorkflowRequest::query()
            ->where('execution_id', $execution->id)
            ->orderBy('id')
            ->get()
            ->all();
        $results = [];

        foreach ($requests as $request) {
            $results[] = $this->replay($request, $useCurrentPrompts, $model);
        }

        return $results;
    }

    /**
     * @param  list<Message>  $messages
     * @param  array<string, mixed>  $clientOptions
     */
    private function replayText(
        string $provider,
        string $model,
        string $systemPrompt,
        array $messages,
        array $clientOptions,
    ): Response {
        /** @var array{text: int, structured: int} $maxTokens */
        $maxTokens = config('ai-workflow.max_tokens');

        $builder = Prism::text()
            ->using($provider, $model)
            ->withMessages($messages)
            ->withMaxTokens($maxTokens['text'])
            ->withClientOptions($clientOptions);

        if ($systemPrompt !== '') {
            $builder = $builder->withSystemPrompt($systemPrompt);
        }

        return $builder->asText();
    }

    /**
     * @param  list<Message>  $messages
     * @param  array<string, mixed>  $clientOptions
     */
    private function replayStructured(
        string $provider,
        string $model,
        string $systemPrompt,
        array $messages,
        AiWorkflowRequest $request,
        array $clientOptions,
    ): StructuredResponse {
        /** @var array{text: int, structured: int} $maxTokens */
        $maxTokens = config('ai-workflow.max_tokens');

        $schema = $this->reconstructSchema($request);

        $builder = Prism::structured()
            ->using($provider, $model)
            ->withSchema($schema)
            ->withMessages($messages)
            ->withMaxTokens($maxTokens['structured'])
            ->withClientOptions($clientOptions);

        if ($systemPrompt !== '') {
            $builder = $builder->withSystemPrompt($systemPrompt);
        }

        return $builder->asStructured();
    }

    /**
     * Reconstruct an ObjectSchema from the stored schema array.
     */
    private function reconstructSchema(AiWorkflowRequest $request): ObjectSchema
    {
        $schemaData = $request->schema;

        if (! is_array($schemaData)) {
            return new ObjectSchema(
                name: 'replay',
                description: 'Reconstructed schema',
                properties: [new StringSchema('result', 'The result')],
                requiredFields: ['result'],
            );
        }

        return $this->buildObjectSchema($schemaData);
    }

    /**
     * Build an ObjectSchema from a schema array.
     *
     * @param  array<string, mixed>  $data
     */
    private function buildObjectSchema(array $data): ObjectSchema
    {
        $properties = [];

        /** @var array<string, array<string, mixed>> $propertiesData */
        $propertiesData = $data['properties'] ?? [];

        /** @var list<string> $requiredFields */
        $requiredFields = $data['required'] ?? [];

        foreach ($propertiesData as $name => $prop) {
            $description = is_string($prop['description'] ?? null) ? $prop['description'] : '';
            $type = is_string($prop['type'] ?? null) ? $prop['type'] : 'string';

            if (isset($prop['enum']) && is_array($prop['enum'])) {
                /** @var list<string|int> $enumValues */
                $enumValues = $prop['enum'];
                $properties[] = new EnumSchema($name, $description, $enumValues);
            } else {
                $properties[] = match ($type) {
                    'object' => $this->buildObjectSchema(array_merge($prop, ['name' => $name])),
                    'integer', 'number' => new NumberSchema($name, $description),
                    'boolean' => new BooleanSchema($name, $description),
                    'array' => new ArraySchema($name, $description, new StringSchema('item', '')),
                    default => new StringSchema($name, $description),
                };
            }
        }

        $schemaName = is_string($data['name'] ?? null) ? $data['name'] : 'schema';
        $schemaDescription = is_string($data['description'] ?? null) ? $data['description'] : '';

        return new ObjectSchema(
            name: $schemaName,
            description: $schemaDescription,
            properties: $properties,
            requiredFields: $requiredFields,
        );
    }
}
