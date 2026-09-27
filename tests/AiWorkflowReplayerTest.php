<?php

declare(strict_types=1);

namespace AiWorkflow\Tests;

use AiWorkflow\AiService;
use AiWorkflow\AiWorkflowReplayer;
use AiWorkflow\Models\AiWorkflowRequest;
use AiWorkflow\PromptData;
use AiWorkflow\PromptService;
use AiWorkflow\Tests\Concerns\MakesTestFixtures;
use Prism\Prism\Contracts\Schema;
use Prism\Prism\Enums\FinishReason;
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
use Prism\Prism\Testing\PrismFake;
use Prism\Prism\Testing\StructuredResponseFake;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\Text\Response;
use Prism\Prism\ValueObjects\Messages\UserMessage;

class AiWorkflowReplayerTest extends DatabaseTestCase
{
    use MakesTestFixtures;

    public function test_replay_text_request(): void
    {
        // Record a request
        Prism::fake([
            TextResponseFake::make()->withText('Original response')->withFinishReason(FinishReason::Stop),
        ]);

        $service = app(AiService::class);
        $service->sendMessages(collect([new UserMessage('Hello')]), $this->makePrompt());

        $recorded = AiWorkflowRequest::first();
        $this->assertNotNull($recorded);

        // Replay it
        Prism::fake([
            TextResponseFake::make()->withText('Replayed response')->withFinishReason(FinishReason::Stop),
        ]);

        $replayer = app(AiWorkflowReplayer::class);
        $result = $replayer->replay($recorded);

        $this->assertInstanceOf(Response::class, $result);
        $this->assertSame('Replayed response', $result->text);
    }

    public function test_replay_structured_request(): void
    {
        Prism::fake([
            StructuredResponseFake::make()
                ->withStructured(['answer' => 'original'])
                ->withFinishReason(FinishReason::Stop),
        ]);

        $service = app(AiService::class);
        $service->sendStructuredMessages(
            collect([new UserMessage('Hello')]),
            $this->makePrompt(),
            $this->makeSchema(),
        );

        $recorded = AiWorkflowRequest::first();
        $this->assertNotNull($recorded);
        $this->assertSame('sendStructuredMessages', $recorded->method);

        // Replay
        Prism::fake([
            StructuredResponseFake::make()
                ->withStructured(['answer' => 'replayed'])
                ->withFinishReason(FinishReason::Stop),
        ]);

        $replayer = app(AiWorkflowReplayer::class);
        $result = $replayer->replay($recorded);

        $this->assertInstanceOf(StructuredResponse::class, $result);
        $this->assertSame(['answer' => 'replayed'], $result->structured);
    }

    public function test_replay_with_model_override(): void
    {
        Prism::fake([
            TextResponseFake::make()->withText('Original')->withFinishReason(FinishReason::Stop),
        ]);

        $service = app(AiService::class);
        $service->sendMessages(collect([new UserMessage('Hello')]), $this->makePrompt());

        $recorded = AiWorkflowRequest::first();
        $this->assertNotNull($recorded);
        $this->assertSame('test-model', $recorded->model);

        // Replay with different model (must be in provider:model format)
        Prism::fake([
            TextResponseFake::make()->withText('From new model')->withFinishReason(FinishReason::Stop),
        ]);

        $fake = Prism::fake([
            TextResponseFake::make()->withText('From new model')->withFinishReason(FinishReason::Stop),
        ]);

        $replayer = app(AiWorkflowReplayer::class);
        $result = $replayer->replay($recorded, model: 'anthropic:different-model');

        $this->assertInstanceOf(Response::class, $result);
        $this->assertSame('From new model', $result->text);

        $fake->assertRequest(function (array $recorded): void {
            $this->assertCount(1, $recorded);
            $this->assertSame('different-model', $recorded[0]->model());
            $this->assertSame('anthropic', $recorded[0]->provider());
        });
    }

    public function test_replay_applies_the_reasoning_setting_from_the_prompt(): void
    {
        $recorded = AiWorkflowRequest::create([
            'prompt_id' => 'reasoning_effort_prompt',
            'method' => 'sendMessages',
            'provider' => 'openrouter',
            'model' => 'test/model',
            'system_prompt' => 'You are a helpful reasoning assistant.',
            'messages' => [['type' => 'user', 'content' => 'Hello']],
            'finish_reason' => 'stop',
            'duration_ms' => 100,
        ]);

        $fake = Prism::fake([
            TextResponseFake::make()->withText('Replayed')->withFinishReason(FinishReason::Stop),
        ]);

        app(AiWorkflowReplayer::class)->replay($recorded);

        // Reasoning is carried in provider options, so a replay that omits them
        // measures the model at its default effort, not the prompt's.
        $fake->assertRequest(function (array $requests): void {
            $this->assertSame(['effort' => 'high'], $requests[0]->providerOptions('reasoning'));
        });
    }

    public function test_replay_applies_the_reasoning_setting_to_a_structured_request(): void
    {
        $recorded = AiWorkflowRequest::create([
            'prompt_id' => 'reasoning_effort_prompt',
            'method' => 'sendStructuredMessages',
            'provider' => 'openrouter',
            'model' => 'test/model',
            'system_prompt' => 'You are a helpful reasoning assistant.',
            'messages' => [['type' => 'user', 'content' => 'Hello']],
            'finish_reason' => 'stop',
            'duration_ms' => 100,
            'schema' => [
                'type' => 'object',
                'name' => 'answer',
                'description' => 'An answer',
                'properties' => [
                    'answer' => ['type' => 'string', 'description' => 'The answer'],
                ],
                'required' => ['answer'],
            ],
        ]);

        $fake = Prism::fake([
            StructuredResponseFake::make()
                ->withStructured(['answer' => 'replayed'])
                ->withFinishReason(FinishReason::Stop),
        ]);

        app(AiWorkflowReplayer::class)->replay($recorded);

        // Eval runs go through replayStructured(), which builds its request
        // separately from replayText(), so the text test above covers none of it.
        $fake->assertRequest(function (array $requests): void {
            $this->assertSame(['effort' => 'high'], $requests[0]->providerOptions('reasoning'));
        });
    }

    public function test_replay_falls_back_to_the_recorded_prompt_when_the_file_is_gone(): void
    {
        $recorded = AiWorkflowRequest::create([
            'prompt_id' => 'prompt_that_no_longer_exists',
            'method' => 'sendMessages',
            'provider' => 'openrouter',
            'model' => 'test/model',
            'system_prompt' => 'Recorded system prompt.',
            'messages' => [['type' => 'user', 'content' => 'Hello']],
            'finish_reason' => 'stop',
            'duration_ms' => 100,
        ]);

        $fake = Prism::fake([
            TextResponseFake::make()->withText('Replayed')->withFinishReason(FinishReason::Stop),
        ]);

        $result = app(AiWorkflowReplayer::class)->replay($recorded);

        $this->assertSame('Replayed', $result->text);

        $fake->assertRequest(function (array $requests): void {
            $this->assertSame('Recorded system prompt.', $requests[0]->systemPrompts()[0]->content);
            $this->assertSame([], $requests[0]->providerOptions());
        });
    }

    public function test_replay_with_current_prompts(): void
    {
        Prism::fake([
            TextResponseFake::make()->withText('Original')->withFinishReason(FinishReason::Stop),
        ]);

        // Use test_prompt which exists in fixtures
        $service = app(AiService::class);
        $service->sendMessages(collect([new UserMessage('Hello')]), $this->makePrompt('test_prompt', 'openrouter:old-model'));

        $recorded = AiWorkflowRequest::first();
        $this->assertNotNull($recorded);
        $this->assertSame('old-model', $recorded->model);

        // Replay with current prompts — should load test_prompt from fixtures
        Prism::fake([
            TextResponseFake::make()->withText('From current prompt')->withFinishReason(FinishReason::Stop),
        ]);

        $replayer = app(AiWorkflowReplayer::class);
        $result = $replayer->replay($recorded, useCurrentPrompts: true);

        $this->assertInstanceOf(Response::class, $result);
        $this->assertSame('From current prompt', $result->text);
    }

    public function test_replay_with_current_prompts_and_model_override(): void
    {
        Prism::fake([
            TextResponseFake::make()->withText('Original')->withFinishReason(FinishReason::Stop),
        ]);

        $service = app(AiService::class);
        $service->sendMessages(collect([new UserMessage('Hello')]), $this->makePrompt('test_prompt', 'openrouter:old-model'));

        $recorded = AiWorkflowRequest::first();
        $this->assertNotNull($recorded);

        // Replay: useCurrentPrompts loads prompt text from file, but model override wins
        Prism::fake([
            TextResponseFake::make()->withText('Override model')->withFinishReason(FinishReason::Stop),
        ]);

        $replayer = app(AiWorkflowReplayer::class);
        $result = $replayer->replay($recorded, useCurrentPrompts: true, model: 'anthropic:override-model');

        $this->assertInstanceOf(Response::class, $result);
        $this->assertSame('Override model', $result->text);
    }

    public function test_replay_across_models(): void
    {
        Prism::fake([
            TextResponseFake::make()->withText('Original')->withFinishReason(FinishReason::Stop),
        ]);

        $service = app(AiService::class);
        $service->sendMessages(collect([new UserMessage('Hello')]), $this->makePrompt());

        $recorded = AiWorkflowRequest::first();
        $this->assertNotNull($recorded);

        // Replay across 3 models
        $fake = Prism::fake([
            TextResponseFake::make()->withText('Model A response')->withFinishReason(FinishReason::Stop),
            TextResponseFake::make()->withText('Model B response')->withFinishReason(FinishReason::Stop),
            TextResponseFake::make()->withText('Model C response')->withFinishReason(FinishReason::Stop),
        ]);

        $replayer = app(AiWorkflowReplayer::class);
        $results = $replayer->replayAcrossModels($recorded, [
            'openrouter:model-a',
            'anthropic:model-b',
            'openai:model-c',
        ]);

        $this->assertCount(3, $results);
        $this->assertArrayHasKey('openrouter:model-a', $results);
        $this->assertArrayHasKey('anthropic:model-b', $results);
        $this->assertArrayHasKey('openai:model-c', $results);
        $this->assertSame('Model A response', $results['openrouter:model-a']->text);
        $this->assertSame('Model B response', $results['anthropic:model-b']->text);
        $this->assertSame('Model C response', $results['openai:model-c']->text);

        $fake->assertRequest(function (array $recorded): void {
            $this->assertCount(3, $recorded);
            $this->assertSame('model-a', $recorded[0]->model());
            $this->assertSame('openrouter', $recorded[0]->provider());
            $this->assertSame('model-b', $recorded[1]->model());
            $this->assertSame('anthropic', $recorded[1]->provider());
            $this->assertSame('model-c', $recorded[2]->model());
            $this->assertSame('openai', $recorded[2]->provider());
        });
    }

    public function test_replay_execution(): void
    {
        Prism::fake([
            TextResponseFake::make()->withText('First')->withFinishReason(FinishReason::Stop),
            TextResponseFake::make()->withText('Second')->withFinishReason(FinishReason::Stop),
        ]);

        $service = app(AiService::class);
        $service->startExecution('test_workflow');
        $service->sendMessages(collect([new UserMessage('First')]), $this->makePrompt('test_prompt'));
        $service->sendMessages(collect([new UserMessage('Second')]), $this->makePrompt('fallback_prompt', 'openrouter:test/primary-model'));
        $execution = $service->endExecution();

        $this->assertNotNull($execution);
        $this->assertSame(2, $execution->requests()->count());

        // Replay the entire execution
        Prism::fake([
            TextResponseFake::make()->withText('Replay 1')->withFinishReason(FinishReason::Stop),
            TextResponseFake::make()->withText('Replay 2')->withFinishReason(FinishReason::Stop),
        ]);

        $replayer = app(AiWorkflowReplayer::class);
        $results = $replayer->replayExecution($execution);

        $this->assertCount(2, $results);
        $this->assertSame('Replay 1', $results[0]->text);
        $this->assertSame('Replay 2', $results[1]->text);
    }

    // --- Schema reconstruction ---

    public function test_replay_structured_rebuilds_the_recorded_schema_from_the_same_prism_classes(): void
    {
        $schema = $this->makeReplayableSchema();

        $fake = $this->replayStructuredWithSchema($schema->toArray(), $schema->name());

        $fake->assertRequest(function (array $requests) use ($schema): void {
            $this->assertEquals($schema, $requests[0]->schema());
        });
    }

    public function test_replay_structured_falls_back_to_the_name_schema_when_the_row_has_no_schema_name(): void
    {
        $fake = $this->replayStructuredWithSchema($this->makeReplayableSchema()->toArray());

        $fake->assertRequest(function (array $requests): void {
            $this->assertSame('schema', $requests[0]->schema()->name());
        });
    }

    public function test_replay_structured_rebuilds_the_schema_when_the_database_reorders_its_keys(): void
    {
        $recorded = $this->reverseKeysRecursively($this->makeReplayableSchema()->toArray());

        $fake = $this->replayStructuredWithSchema($recorded);

        $fake->assertRequest(function (array $requests) use ($recorded): void {
            $sent = $requests[0]->schema();

            $this->assertEquals($recorded, $sent->toArray());
            $this->assertNotContains(RawSchema::class, $this->schemaClasses($sent));
        });
    }

    public function test_replay_structured_restores_property_order_from_required_where_the_database_sorts_keys(): void
    {
        $this->app->instance(AiWorkflowReplayer::class, new class(app(PromptService::class)) extends AiWorkflowReplayer
        {
            protected function restoresPropertyOrder(AiWorkflowRequest $request): bool
            {
                return true;
            }
        });

        $schema = $this->makeReplayableSchema();

        $fake = $this->replayStructuredWithSchema($this->reverseKeysRecursively($schema->toArray()), $schema->name());

        $fake->assertRequest(function (array $requests) use ($schema): void {
            $this->assertEquals($schema, $requests[0]->schema());
        });
    }

    public function test_replay_structured_sends_an_any_of_with_an_untyped_option_as_recorded(): void
    {
        $recorded = [
            'description' => 'Statistics',
            'type' => 'object',
            'properties' => [
                'value' => ['anyOf' => [['description' => 'Anything']], 'description' => 'A value'],
            ],
            'required' => ['value'],
            'additionalProperties' => false,
        ];

        $fake = $this->replayStructuredWithSchema($recorded);

        $fake->assertRequest(function (array $requests) use ($recorded): void {
            $sent = $requests[0]->schema();

            $this->assertInstanceOf(ObjectSchema::class, $sent);
            $this->assertInstanceOf(RawSchema::class, $sent->properties[0]);
            $this->assertSame($recorded, $sent->toArray());
        });
    }

    public function test_replay_structured_sends_nodes_it_cannot_rebuild_exactly_as_recorded(): void
    {
        $recorded = [
            'description' => 'Statistics',
            'type' => 'object',
            'properties' => [
                'count' => ['type' => 'integer', 'description' => 'Count', 'minimum' => 0],
                'tags' => ['type' => 'array', 'description' => 'Tags'],
                'label' => ['type' => 'string', 'description' => 'Label', 'minLength' => 3],
                'ratio' => ['description' => 'Ratio', 'type' => 'number'],
            ],
            'required' => ['count', 'tags', 'label', 'ratio'],
            'additionalProperties' => false,
        ];

        $fake = $this->replayStructuredWithSchema($recorded);

        $fake->assertRequest(function (array $requests) use ($recorded): void {
            $sent = $requests[0]->schema();

            $this->assertInstanceOf(ObjectSchema::class, $sent);
            $this->assertSame($recorded, $sent->toArray());
            $this->assertInstanceOf(RawSchema::class, $sent->properties[0]);
            $this->assertInstanceOf(RawSchema::class, $sent->properties[1]);
            $this->assertInstanceOf(RawSchema::class, $sent->properties[2]);
            $this->assertInstanceOf(NumberSchema::class, $sent->properties[3]);
        });
    }

    // --- Template variables for faithful replay ---

    public function test_replay_with_current_prompts_uses_stored_template_variables(): void
    {
        Prism::fake([
            TextResponseFake::make()->withText('Original')->withFinishReason(FinishReason::Stop),
        ]);

        $service = app(AiService::class);
        $prompt = new PromptData(
            id: 'template_prompt',
            model: 'openrouter:old-model',
            prompt: 'You are helping Jane with their Premium subscription.',
            variables: ['customer_name' => 'Jane', 'product' => 'Premium'],
        );
        $service->sendMessages(collect([new UserMessage('Hello')]), $prompt);

        $recorded = AiWorkflowRequest::first();
        $this->assertNotNull($recorded);
        $this->assertSame(['customer_name' => 'Jane', 'product' => 'Premium'], $recorded->template_variables);

        // Replay with current prompts — should re-render the template with stored variables
        Prism::fake([
            TextResponseFake::make()->withText('Replayed with vars')->withFinishReason(FinishReason::Stop),
        ]);

        $replayer = app(AiWorkflowReplayer::class);
        $result = $replayer->replay($recorded, useCurrentPrompts: true);

        $this->assertInstanceOf(Response::class, $result);
        $this->assertSame('Replayed with vars', $result->text);
    }

    private function makeReplayableSchema(): ObjectSchema
    {
        return new ObjectSchema(
            name: 'TicketTriage',
            description: 'A support ticket triage',
            properties: [
                new EnumSchema('priority', 'How urgent the ticket is', ['low', 'medium', 'high'], nullable: true),
                new NumberSchema('confidence', 'Confidence in the triage', nullable: true, maximum: 1.0, minimum: 0.0),
                new BooleanSchema('needs_human', 'Whether a person must reply'),
                new StringSchema('reference', 'Order reference', nullable: true, pattern: '^[A-Z]{3}-\d+$'),
                new ArraySchema('tags', 'Tags that apply', new StringSchema('item', 'A tag'), minItems: 1, maxItems: 5),
                new ArraySchema('actions', 'Follow-up actions', new ObjectSchema(
                    name: 'item',
                    description: 'A follow-up action',
                    properties: [
                        new StringSchema('summary', 'What to do'),
                        new NumberSchema('due_in_days', 'Days until it is due', nullable: true),
                    ],
                    requiredFields: ['summary', 'due_in_days'],
                )),
                new ObjectSchema(
                    name: 'customer',
                    description: 'The customer, when known',
                    properties: [
                        new StringSchema('email', 'Email address', format: 'email'),
                    ],
                    requiredFields: ['email'],
                    nullable: true,
                ),
                new AnyOfSchema(
                    schemas: [
                        new StringSchema('item', 'A queue name'),
                        new NumberSchema('item', 'An agent ID', minimum: 1.0),
                    ],
                    name: 'assignee',
                    description: 'Who should pick it up',
                    nullable: true,
                ),
            ],
            requiredFields: ['priority', 'confidence', 'needs_human', 'reference', 'tags', 'actions', 'customer', 'assignee'],
        );
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    private function replayStructuredWithSchema(array $schema, ?string $schemaName = null): PrismFake
    {
        $request = AiWorkflowRequest::create([
            'prompt_id' => 'test',
            'method' => 'sendStructuredMessages',
            'provider' => 'openrouter',
            'model' => 'test-model',
            'system_prompt' => 'Test.',
            'messages' => [['type' => 'user', 'content' => 'Hello']],
            'finish_reason' => 'stop',
            'duration_ms' => 100,
            'schema' => $schema,
            'schema_name' => $schemaName,
        ]);

        $fake = Prism::fake([
            StructuredResponseFake::make()->withStructured([])->withFinishReason(FinishReason::Stop),
        ]);

        app(AiWorkflowReplayer::class)->replay($request->refresh());

        return $fake;
    }

    private function reverseKeysRecursively(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $reversed = array_map(fn (mixed $entry): mixed => $this->reverseKeysRecursively($entry), $value);

        return array_is_list($reversed) ? $reversed : array_reverse($reversed, true);
    }

    /**
     * @return list<class-string<Schema>>
     */
    private function schemaClasses(Schema $schema): array
    {
        $children = match (true) {
            $schema instanceof ObjectSchema => $schema->properties,
            $schema instanceof ArraySchema => [$schema->items],
            default => [],
        };

        return array_merge([$schema::class], ...array_map($this->schemaClasses(...), array_values($children)));
    }
}
