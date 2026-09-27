<?php

declare(strict_types=1);

namespace AiWorkflow;

use AiWorkflow\Models\AiWorkflowExecution;
use AiWorkflow\Models\AiWorkflowRequest;
use Prism\Prism\Contracts\Message;
use Prism\Prism\Contracts\Schema;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\AnyOfSchema;
use Prism\Prism\Schema\ArraySchema;
use Prism\Prism\Schema\BooleanSchema;
use Prism\Prism\Schema\EnumSchema;
use Prism\Prism\Schema\NumberSchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\RawSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Structured\Response as StructuredResponse;
use Prism\Prism\Text\Response;
use RuntimeException;

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

        // The property is annotated as an array, but Laravel's array cast is
        // json_decode with no type check, so a scalar in this column stays a scalar.
        $storedVariables = $request->template_variables;
        $templateVariables = is_array($storedVariables) ? $storedVariables : [];

        // Loaded even when replaying the recorded text: the reasoning setting
        // lives in the prompt's front matter, not on the request, so a replay
        // without it measures the model at its default effort.
        $prompt = $this->loadPrompt($request->prompt_id, $templateVariables);

        if ($useCurrentPrompts && $prompt instanceof PromptData) {
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
                $replayProvider, $replayModel, $systemPrompt, $messages, $request, $clientOptions, $prompt,
            ),
            default => $this->replayText(
                $replayProvider, $replayModel, $systemPrompt, $messages, $clientOptions, $prompt,
            ),
        };
    }

    /**
     * The prompt behind a recorded request, or null when it cannot be loaded.
     *
     * Prompts get renamed and deleted long after a request was recorded, and an
     * eval run is a long sequence of paid calls. Swallowing the failure costs one
     * replay its current text and reasoning setting; throwing would cost the run.
     *
     * @param  array<string, mixed>  $variables
     */
    private function loadPrompt(string $id, array $variables): ?PromptData
    {
        try {
            return $this->promptService->load($id, $variables);
        } catch (RuntimeException) {
            return null;
        }
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
        ?PromptData $prompt,
    ): Response {
        /** @var array{text: int, structured: int} $maxTokens */
        $maxTokens = config('ai-workflow.max_tokens');

        $builder = Prism::text()
            ->using($provider, $model)
            ->withMessages($messages)
            ->withMaxTokens($maxTokens['text'])
            ->withClientOptions($clientOptions);

        $reasoningOptions = $prompt?->resolveReasoningOptions($provider, $maxTokens['text']) ?? [];

        if ($reasoningOptions !== []) {
            $builder = $builder->withProviderOptions($reasoningOptions);
        }

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
        ?PromptData $prompt,
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

        $reasoningOptions = $prompt?->resolveReasoningOptions($provider, $maxTokens['structured']) ?? [];

        if ($reasoningOptions !== []) {
            $builder = $builder->withProviderOptions($reasoningOptions);
        }

        if ($systemPrompt !== '') {
            $builder = $builder->withSystemPrompt($systemPrompt);
        }

        return StructuredResponseGuard::rejectNonFiniteNumbers($builder->asStructured());
    }

    private function reconstructSchema(AiWorkflowRequest $request): Schema
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

        return $this->buildSchema($request->schema_name ?? 'schema', $schemaData, $this->restoresPropertyOrder($request));
    }

    /**
     * Whether to put each object's properties back in the order of its `required` list.
     *
     * Assumes `required` lists the properties in the order they were sent.
     */
    protected function restoresPropertyOrder(AiWorkflowRequest $request): bool
    {
        return $request->getConnection()->getDriverName() === 'mysql';
    }

    /**
     * Rebuild a stored JSON Schema node as the Prism class it was serialised from,
     * or as a RawSchema when no class reproduces the node exactly.
     *
     * Only Prism's Gemini handler depends on the class. It converts typed classes
     * into Gemini's schema format, which allows one "type" per node, but does not
     * convert a RawSchema.
     *
     * @param  array<string, mixed>  $data
     */
    private function buildSchema(string $name, array $data, bool $restorePropertyOrder): Schema
    {
        $schema = $this->buildTypedSchema($name, $data, $restorePropertyOrder);

        if ($schema instanceof Schema && $this->matchesRecorded($schema, $data)) {
            return $schema;
        }

        return new RawSchema($name, $data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function matchesRecorded(Schema $schema, array $data): bool
    {
        $rebuilt = json_decode(json_encode($schema->toArray(), JSON_THROW_ON_ERROR), associative: true, flags: JSON_THROW_ON_ERROR);

        return $this->sortKeysRecursively($rebuilt) === $this->sortKeysRecursively($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function buildTypedSchema(string $name, array $data, bool $restorePropertyOrder): ?Schema
    {
        if (array_key_exists('anyOf', $data)) {
            return $this->buildAnyOfSchema($name, $data, $restorePropertyOrder);
        }

        $description = $data['description'] ?? null;

        if (! is_string($description)) {
            return null;
        }

        [$type, $nullable] = $this->resolveType($data['type'] ?? null);

        if (array_key_exists('enum', $data)) {
            return $this->buildEnumSchema($name, $description, $data['enum'], $nullable);
        }

        return match ($type) {
            'object' => $this->buildObjectSchema($name, $description, $data, $nullable, $restorePropertyOrder),
            'array' => $this->buildArraySchema($name, $description, $data, $nullable, $restorePropertyOrder),
            'number' => new NumberSchema(
                name: $name,
                description: $description,
                nullable: $nullable,
                multipleOf: $this->floatOrNull($data['multipleOf'] ?? null),
                maximum: $this->floatOrNull($data['maximum'] ?? null),
                exclusiveMaximum: $this->floatOrNull($data['exclusiveMaximum'] ?? null),
                minimum: $this->floatOrNull($data['minimum'] ?? null),
                exclusiveMinimum: $this->floatOrNull($data['exclusiveMinimum'] ?? null),
            ),
            'boolean' => new BooleanSchema($name, $description, $nullable),
            'string' => new StringSchema(
                name: $name,
                description: $description,
                nullable: $nullable,
                pattern: $this->stringOrNull($data['pattern'] ?? null),
                format: $this->stringOrNull($data['format'] ?? null),
            ),
            default => null,
        };
    }

    /**
     * Split a JSON Schema "type" into its single non-null type (null unless
     * there is exactly one) and whether null is allowed.
     *
     * @return array{?string, bool}
     */
    private function resolveType(mixed $type): array
    {
        if (is_string($type)) {
            return [$type, false];
        }

        if (! is_array($type)) {
            return [null, false];
        }

        $nonNullTypes = array_values(array_filter($type, static fn (mixed $entry): bool => $entry !== 'null'));
        $singleType = count($nonNullTypes) === 1 && is_string($nonNullTypes[0]) ? $nonNullTypes[0] : null;

        return [$singleType, in_array('null', $type, true)];
    }

    private function buildEnumSchema(string $name, string $description, mixed $options, bool $nullable): ?EnumSchema
    {
        if (! is_array($options)) {
            return null;
        }

        $scalarOptions = [];

        foreach ($options as $option) {
            if (! is_string($option) && ! is_int($option) && ! is_float($option)) {
                return null;
            }

            $scalarOptions[] = $option;
        }

        return new EnumSchema($name, $description, $scalarOptions, $nullable);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function buildObjectSchema(string $name, string $description, array $data, bool $nullable, bool $restorePropertyOrder): ?ObjectSchema
    {
        $propertiesData = $this->stringKeyed($data['properties'] ?? []);
        $requiredData = $data['required'] ?? [];
        $allowAdditionalProperties = $data['additionalProperties'] ?? false;

        if ($propertiesData === null || ! is_array($requiredData) || ! is_bool($allowAdditionalProperties)) {
            return null;
        }

        $requiredFields = [];

        foreach ($requiredData as $field) {
            if (! is_string($field)) {
                return null;
            }

            $requiredFields[] = $field;
        }

        $properties = [];

        foreach ($propertiesData as $propertyName => $propertyData) {
            $property = $this->stringKeyed($propertyData);

            if ($property === null) {
                return null;
            }

            $properties[] = $this->buildSchema($propertyName, $property, $restorePropertyOrder);
        }

        if ($restorePropertyOrder) {
            $properties = $this->orderByRequiredFields($properties, $requiredFields);
        }

        return new ObjectSchema(
            name: $name,
            description: $description,
            properties: $properties,
            requiredFields: $requiredFields,
            allowAdditionalProperties: $allowAdditionalProperties,
            nullable: $nullable,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function buildArraySchema(string $name, string $description, array $data, bool $nullable, bool $restorePropertyOrder): ?ArraySchema
    {
        $items = $this->stringKeyed($data['items'] ?? null);

        if ($items === null) {
            return null;
        }

        return new ArraySchema(
            name: $name,
            description: $description,
            items: $this->buildSchema('item', $items, $restorePropertyOrder),
            nullable: $nullable,
            minItems: $this->intOrNull($data['minItems'] ?? null),
            maxItems: $this->intOrNull($data['maxItems'] ?? null),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function buildAnyOfSchema(string $name, array $data, bool $restorePropertyOrder): ?AnyOfSchema
    {
        $options = $data['anyOf'] ?? null;
        $description = $data['description'] ?? null;

        if (! is_array($options) || ! array_is_list($options) || ($description !== null && ! is_string($description))) {
            return null;
        }

        $nullable = $options !== [] && $options[array_key_last($options)] === ['type' => 'null'];

        if ($nullable) {
            array_pop($options);
        }

        $schemas = [];

        foreach ($options as $option) {
            $optionData = $this->stringKeyed($option);

            // AnyOfSchema::toArray() throws for an option without "type", "anyOf" or "oneOf".
            if ($optionData === null || ($optionData['type'] ?? $optionData['anyOf'] ?? $optionData['oneOf'] ?? null) === null) {
                return null;
            }

            $schemas[] = $this->buildSchema('item', $optionData, $restorePropertyOrder);
        }

        return new AnyOfSchema($schemas, $name, $description, $nullable);
    }

    /**
     * @param  list<Schema>  $properties
     * @param  list<string>  $requiredFields
     * @return list<Schema>
     */
    private function orderByRequiredFields(array $properties, array $requiredFields): array
    {
        $byName = [];

        foreach ($properties as $property) {
            $byName[$property->name()] = $property;
        }

        $names = array_map(strval(...), array_keys($byName));
        $required = $requiredFields;
        sort($names);
        sort($required);

        if (count($byName) !== count($properties) || $names !== $required) {
            return $properties;
        }

        $ordered = [];

        foreach ($requiredFields as $field) {
            $property = $byName[$field] ?? null;

            if ($property === null) {
                return $properties;
            }

            $ordered[] = $property;
        }

        return $ordered;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function stringKeyed(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $result = [];

        foreach ($value as $key => $entry) {
            if (! is_string($key)) {
                return null;
            }

            $result[$key] = $entry;
        }

        return $result;
    }

    private function sortKeysRecursively(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $sorted = array_map(fn (mixed $entry): mixed => $this->sortKeysRecursively($entry), $value);

        if (! array_is_list($sorted)) {
            ksort($sorted);
        }

        return $sorted;
    }

    private function floatOrNull(mixed $value): ?float
    {
        if (is_int($value)) {
            return (float) $value;
        }

        return is_float($value) ? $value : null;
    }

    private function intOrNull(mixed $value): ?int
    {
        return is_int($value) ? $value : null;
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
