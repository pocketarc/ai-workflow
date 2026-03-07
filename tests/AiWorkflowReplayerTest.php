<?php

declare(strict_types=1);

namespace AiWorkflow\Tests;

use AiWorkflow\AiService;
use AiWorkflow\AiWorkflowReplayer;
use AiWorkflow\Models\AiWorkflowRequest;
use AiWorkflow\PromptData;
use AiWorkflow\Tests\Concerns\MakesTestFixtures;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Structured\Response as StructuredResponse;
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

    // --- Schema reconstruction type mapping ---

    public function test_replay_structured_reconstructs_number_types(): void
    {
        Prism::fake([
            StructuredResponseFake::make()
                ->withStructured(['count' => 5, 'ratio' => 0.5])
                ->withFinishReason(FinishReason::Stop),
        ]);

        $request = AiWorkflowRequest::create([
            'prompt_id' => 'test',
            'method' => 'sendStructuredMessages',
            'provider' => 'openrouter',
            'model' => 'test-model',
            'system_prompt' => 'Test.',
            'messages' => [['type' => 'user', 'content' => 'Hello']],
            'finish_reason' => 'stop',
            'duration_ms' => 100,
            'schema' => [
                'type' => 'object',
                'name' => 'stats',
                'description' => 'Statistics',
                'properties' => [
                    'count' => ['type' => 'integer', 'description' => 'Count'],
                    'ratio' => ['type' => 'number', 'description' => 'Ratio'],
                ],
                'required' => ['count', 'ratio'],
            ],
        ]);

        $replayer = app(AiWorkflowReplayer::class);
        $result = $replayer->replay($request);

        $this->assertInstanceOf(StructuredResponse::class, $result);
        $this->assertSame(5, $result->structured['count']);
    }

    public function test_replay_structured_reconstructs_boolean_type(): void
    {
        Prism::fake([
            StructuredResponseFake::make()
                ->withStructured(['active' => true])
                ->withFinishReason(FinishReason::Stop),
        ]);

        $request = AiWorkflowRequest::create([
            'prompt_id' => 'test',
            'method' => 'sendStructuredMessages',
            'provider' => 'openrouter',
            'model' => 'test-model',
            'system_prompt' => 'Test.',
            'messages' => [['type' => 'user', 'content' => 'Hello']],
            'finish_reason' => 'stop',
            'duration_ms' => 100,
            'schema' => [
                'type' => 'object',
                'name' => 'status',
                'description' => 'Status check',
                'properties' => [
                    'active' => ['type' => 'boolean', 'description' => 'Is active'],
                ],
                'required' => ['active'],
            ],
        ]);

        $replayer = app(AiWorkflowReplayer::class);
        $result = $replayer->replay($request);

        $this->assertInstanceOf(StructuredResponse::class, $result);
        $this->assertTrue($result->structured['active']);
    }

    public function test_replay_structured_reconstructs_array_type(): void
    {
        Prism::fake([
            StructuredResponseFake::make()
                ->withStructured(['items' => ['a', 'b']])
                ->withFinishReason(FinishReason::Stop),
        ]);

        $request = AiWorkflowRequest::create([
            'prompt_id' => 'test',
            'method' => 'sendStructuredMessages',
            'provider' => 'openrouter',
            'model' => 'test-model',
            'system_prompt' => 'Test.',
            'messages' => [['type' => 'user', 'content' => 'Hello']],
            'finish_reason' => 'stop',
            'duration_ms' => 100,
            'schema' => [
                'type' => 'object',
                'name' => 'list',
                'description' => 'Item list',
                'properties' => [
                    'items' => ['type' => 'array', 'description' => 'Items'],
                ],
                'required' => ['items'],
            ],
        ]);

        $replayer = app(AiWorkflowReplayer::class);
        $result = $replayer->replay($request);

        $this->assertInstanceOf(StructuredResponse::class, $result);
        $this->assertSame(['a', 'b'], $result->structured['items']);
    }

    public function test_replay_structured_reconstructs_enum_type(): void
    {
        Prism::fake([
            StructuredResponseFake::make()
                ->withStructured(['status' => 'active'])
                ->withFinishReason(FinishReason::Stop),
        ]);

        $request = AiWorkflowRequest::create([
            'prompt_id' => 'test',
            'method' => 'sendStructuredMessages',
            'provider' => 'openrouter',
            'model' => 'test-model',
            'system_prompt' => 'Test.',
            'messages' => [['type' => 'user', 'content' => 'Hello']],
            'finish_reason' => 'stop',
            'duration_ms' => 100,
            'schema' => [
                'type' => 'object',
                'name' => 'record',
                'description' => 'A record',
                'properties' => [
                    'status' => ['type' => 'string', 'description' => 'Status', 'enum' => ['active', 'inactive', 'pending']],
                ],
                'required' => ['status'],
            ],
        ]);

        $replayer = app(AiWorkflowReplayer::class);
        $result = $replayer->replay($request);

        $this->assertInstanceOf(StructuredResponse::class, $result);
        $this->assertSame('active', $result->structured['status']);
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
}
