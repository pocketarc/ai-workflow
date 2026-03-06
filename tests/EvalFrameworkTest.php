<?php

declare(strict_types=1);

namespace AiWorkflow\Tests;

use AiWorkflow\Eval\AiJudge;
use AiWorkflow\Eval\AiWorkflowEvalJudge;
use AiWorkflow\Eval\AiWorkflowEvalResult;
use AiWorkflow\Eval\AiWorkflowEvalRunner;
use AiWorkflow\Models\AiWorkflowEvalRun;
use AiWorkflow\Models\AiWorkflowEvalScore;
use AiWorkflow\Models\AiWorkflowRequest;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Structured\Response as StructuredResponse;
use Prism\Prism\Testing\StructuredResponseFake;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\Text\Response;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;

class EvalFrameworkTest extends DatabaseTestCase
{
    // --- AiJudge ---

    public function test_ai_judge_compares_original_and_new_response(): void
    {
        Prism::fake([
            StructuredResponseFake::make()
                ->withStructured(['score' => 0.85, 'reasoning' => 'Semantically equivalent with minor wording differences'])
                ->withFinishReason(FinishReason::Stop),
        ]);

        $request = $this->createTextRequest(responseText: 'The billing department handles your request.');
        $response = $this->makeTextResponse('Your request is handled by the billing team.');

        $judge = new AiJudge('openrouter:test-model');
        $result = $judge->judge($request, $response);

        $this->assertSame(0.85, $result->score);
        $this->assertSame('Semantically equivalent with minor wording differences', $result->details['reasoning']);
        $this->assertSame('openrouter:test-model', $result->details['judge_model']);
    }

    public function test_ai_judge_handles_structured_responses(): void
    {
        Prism::fake([
            StructuredResponseFake::make()
                ->withStructured(['score' => 0.95, 'reasoning' => 'Same classification, minor case difference'])
                ->withFinishReason(FinishReason::Stop),
        ]);

        $request = $this->createStructuredRequest(['intent' => 'billing', 'payer' => 'John Smith']);
        $response = $this->makeStructuredResponse(['intent' => 'billing', 'payer' => 'john smith']);

        $judge = new AiJudge('openrouter:test-model');
        $result = $judge->judge($request, $response);

        $this->assertSame(0.95, $result->score);
    }

    public function test_ai_judge_clamps_score_to_valid_range(): void
    {
        Prism::fake([
            StructuredResponseFake::make()
                ->withStructured(['score' => 1.5, 'reasoning' => 'Overscored'])
                ->withFinishReason(FinishReason::Stop),
        ]);

        $request = $this->createTextRequest();
        $response = $this->makeTextResponse('Some response');

        $judge = new AiJudge('openrouter:test-model');
        $result = $judge->judge($request, $response);

        $this->assertSame(1.0, $result->score);
    }

    public function test_ai_judge_accepts_custom_prompt(): void
    {
        Prism::fake([
            StructuredResponseFake::make()
                ->withStructured(['score' => 0.7, 'reasoning' => 'Custom assessment'])
                ->withFinishReason(FinishReason::Stop),
        ]);

        $request = $this->createTextRequest();
        $response = $this->makeTextResponse('Some response');

        $judge = new AiJudge('openrouter:test-model', judgePrompt: 'You are a strict judge.');
        $result = $judge->judge($request, $response);

        $this->assertSame(0.7, $result->score);
    }

    // --- AiWorkflowEvalRunner ---

    public function test_eval_runner_creates_run_and_scores(): void
    {
        Prism::fake([
            TextResponseFake::make()
                ->withText('Hello world')
                ->withFinishReason(FinishReason::Stop),
            TextResponseFake::make()
                ->withText('Hi world')
                ->withFinishReason(FinishReason::Stop),
        ]);

        $request = $this->createTextRequest(responseText: 'Hello world');

        $judge = $this->alwaysScoreJudge(0.9);

        $runner = app(AiWorkflowEvalRunner::class);
        $evalRun = $runner->run(
            name: 'Test eval',
            requests: [$request],
            models: ['openrouter:model-a', 'openrouter:model-b'],
            judge: $judge,
        );

        $this->assertInstanceOf(AiWorkflowEvalRun::class, $evalRun);
        $this->assertSame('Test eval', $evalRun->name);
        $this->assertSame(['openrouter:model-a', 'openrouter:model-b'], $evalRun->models);
        $this->assertCount(2, $evalRun->scores);

        $scoreA = $evalRun->scores->where('model', 'openrouter:model-a')->first();
        $this->assertNotNull($scoreA);
        $this->assertSame(0.9, $scoreA->score);
        $this->assertSame('Hello world', $scoreA->response_text);
    }

    public function test_eval_runner_stores_structured_response(): void
    {
        Prism::fake([
            StructuredResponseFake::make()
                ->withStructured(['intent' => 'billing'])
                ->withFinishReason(FinishReason::Stop),
        ]);

        $request = $this->createStructuredRequest(['intent' => 'billing']);

        $runner = app(AiWorkflowEvalRunner::class);
        $evalRun = $runner->run(
            name: 'Structured eval',
            requests: [$request],
            models: ['openrouter:model-a'],
            judge: $this->alwaysScoreJudge(1.0),
        );

        $score = $evalRun->scores->first();
        $this->assertNotNull($score);
        $this->assertNull($score->response_text);
        $this->assertSame(['intent' => 'billing'], $score->structured_response);
    }

    public function test_eval_runner_with_multiple_requests(): void
    {
        Prism::fake([
            TextResponseFake::make()
                ->withText('Response 1')
                ->withFinishReason(FinishReason::Stop),
            TextResponseFake::make()
                ->withText('Response 2')
                ->withFinishReason(FinishReason::Stop),
        ]);

        $request1 = $this->createTextRequest(responseText: 'Match 1');
        $request2 = $this->createTextRequest(responseText: 'Match 2');

        $callCount = 0;
        $judge = new class($callCount) implements AiWorkflowEvalJudge
        {
            public function __construct(private int &$callCount) {}

            public function judge(AiWorkflowRequest $originalRequest, Response|StructuredResponse $response): AiWorkflowEvalResult
            {
                $this->callCount++;

                return new AiWorkflowEvalResult($this->callCount === 1 ? 1.0 : 0.0);
            }
        };

        $runner = app(AiWorkflowEvalRunner::class);
        $evalRun = $runner->run(
            name: 'Multi-request eval',
            requests: [$request1, $request2],
            models: ['openrouter:model-a'],
            judge: $judge,
        );

        $this->assertCount(2, $evalRun->scores);
        $this->assertEqualsWithDelta(0.5, $evalRun->averageScore(), 0.001);
    }

    public function test_eval_runner_stores_config(): void
    {
        Prism::fake([
            TextResponseFake::make()
                ->withText('test')
                ->withFinishReason(FinishReason::Stop),
        ]);

        $request = $this->createTextRequest();

        $runner = app(AiWorkflowEvalRunner::class);
        $evalRun = $runner->run(
            name: 'Config eval',
            requests: [$request],
            models: ['openrouter:model-a'],
            judge: $this->alwaysScoreJudge(0.5),
            config: ['tag' => 'classification'],
        );

        $this->assertSame(['tag' => 'classification'], $evalRun->config);
    }

    public function test_eval_runner_with_custom_judge(): void
    {
        Prism::fake([
            TextResponseFake::make()
                ->withText('anything')
                ->withFinishReason(FinishReason::Stop),
        ]);

        $request = $this->createTextRequest();

        $runner = app(AiWorkflowEvalRunner::class);
        $evalRun = $runner->run(
            name: 'Custom judge eval',
            requests: [$request],
            models: ['openrouter:model-a'],
            judge: $this->alwaysScoreJudge(0.75, ['custom' => true]),
        );

        $score = $evalRun->scores->first();
        $this->assertNotNull($score);
        $this->assertSame(0.75, $score->score);
        $this->assertSame(['custom' => true], $score->details);
    }

    // --- Model helper methods ---

    public function test_eval_run_average_score_per_model(): void
    {
        $evalRun = AiWorkflowEvalRun::create([
            'name' => 'Test',
            'models' => ['model-a', 'model-b'],
        ]);

        $request = $this->createTextRequest();

        AiWorkflowEvalScore::create([
            'eval_run_id' => $evalRun->id,
            'request_id' => $request->id,
            'model' => 'model-a',
            'score' => 0.8,
            'created_at' => now(),
        ]);

        AiWorkflowEvalScore::create([
            'eval_run_id' => $evalRun->id,
            'request_id' => $request->id,
            'model' => 'model-b',
            'score' => 0.4,
            'created_at' => now(),
        ]);

        $this->assertEqualsWithDelta(0.6, $evalRun->averageScore(), 0.001);
        $this->assertEqualsWithDelta(0.8, $evalRun->averageScoreForModel('model-a'), 0.001);
        $this->assertEqualsWithDelta(0.4, $evalRun->averageScoreForModel('model-b'), 0.001);
    }

    // --- EvalRunCommand ---

    public function test_eval_command_requires_judge(): void
    {
        $this->artisan('ai-workflow:eval', ['--models' => 'openrouter:test-model'])
            ->expectsOutput('A judge class is required. Use --judge=App\\Eval\\MyJudge')
            ->assertExitCode(1);
    }

    public function test_eval_command_requires_models(): void
    {
        $this->artisan('ai-workflow:eval', ['--judge' => FixedScoreJudge::class])
            ->expectsOutput('At least one model is required. Use --models=provider:model')
            ->assertExitCode(1);
    }

    public function test_eval_command_validates_judge_class_exists(): void
    {
        $this->artisan('ai-workflow:eval', [
            '--models' => 'openrouter:test-model',
            '--judge' => 'App\\NonExistent\\Judge',
        ])
            ->expectsOutput("Judge class 'App\\NonExistent\\Judge' not found.")
            ->assertExitCode(1);
    }

    public function test_eval_command_warns_on_no_requests(): void
    {
        $this->artisan('ai-workflow:eval', [
            '--models' => 'openrouter:test-model',
            '--judge' => FixedScoreJudge::class,
        ])
            ->expectsOutput('No requests found matching the criteria.')
            ->assertExitCode(0);
    }

    public function test_eval_command_runs_with_tag_filter(): void
    {
        Prism::fake([
            TextResponseFake::make()
                ->withText('Hello')
                ->withFinishReason(FinishReason::Stop),
        ]);

        $this->createTextRequest(responseText: 'Hello', tags: ['classification']);
        $this->createTextRequest(responseText: 'Other', tags: ['unrelated']);

        $this->artisan('ai-workflow:eval', [
            '--models' => 'openrouter:test-model',
            '--tag' => 'classification',
            '--judge' => FixedScoreJudge::class,
            '--name' => 'Test eval run',
        ])->assertExitCode(0);

        $this->assertDatabaseCount('ai_workflow_eval_runs', 1);
        $this->assertDatabaseCount('ai_workflow_eval_scores', 1);
    }

    // --- Helpers ---

    /**
     * @param  array<string, mixed>  $details
     */
    private function alwaysScoreJudge(float $score, array $details = []): AiWorkflowEvalJudge
    {
        return new class($score, $details) implements AiWorkflowEvalJudge
        {
            /**
             * @param  array<string, mixed>  $details
             */
            public function __construct(private readonly float $score, private readonly array $details) {}

            public function judge(AiWorkflowRequest $originalRequest, Response|StructuredResponse $response): AiWorkflowEvalResult
            {
                return new AiWorkflowEvalResult($this->score, $this->details);
            }
        };
    }

    /**
     * @param  list<string>|null  $tags
     */
    private function createTextRequest(string $responseText = 'default response', ?array $tags = null): AiWorkflowRequest
    {
        return AiWorkflowRequest::create([
            'prompt_id' => 'test',
            'method' => 'sendMessages',
            'provider' => 'openrouter',
            'model' => 'test-model',
            'system_prompt' => 'You are a test assistant.',
            'messages' => [['type' => 'user', 'content' => 'Hello']],
            'response_text' => $responseText,
            'finish_reason' => 'stop',
            'duration_ms' => 100,
            'tags' => $tags,
            'created_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $structured
     */
    private function createStructuredRequest(array $structured): AiWorkflowRequest
    {
        return AiWorkflowRequest::create([
            'prompt_id' => 'test',
            'method' => 'sendStructuredMessages',
            'provider' => 'openrouter',
            'model' => 'test-model',
            'system_prompt' => 'Classify this.',
            'messages' => [['type' => 'user', 'content' => 'Hello']],
            'structured_response' => $structured,
            'finish_reason' => 'stop',
            'duration_ms' => 100,
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'intent' => ['type' => 'string', 'description' => 'The intent'],
                ],
                'required' => ['intent'],
            ],
            'created_at' => now(),
        ]);
    }

    private function makeTextResponse(string $text): Response
    {
        return new Response(
            steps: collect([]),
            text: $text,
            finishReason: FinishReason::Stop,
            toolCalls: [],
            toolResults: [],
            usage: new Usage(10, 20),
            meta: new Meta(id: 'test', model: 'test-model'),
            messages: collect([]),
        );
    }

    /**
     * @param  array<string, mixed>  $structured
     */
    private function makeStructuredResponse(array $structured): StructuredResponse
    {
        return new StructuredResponse(
            steps: collect([]),
            text: json_encode($structured, JSON_THROW_ON_ERROR),
            structured: $structured,
            finishReason: FinishReason::Stop,
            usage: new Usage(10, 20),
            meta: new Meta(id: 'test', model: 'test-model'),
        );
    }
}

/**
 * Simple judge fixture for command tests — resolved from container via FQCN.
 */
class FixedScoreJudge implements AiWorkflowEvalJudge
{
    public function judge(AiWorkflowRequest $originalRequest, Response|StructuredResponse $response): AiWorkflowEvalResult
    {
        return new AiWorkflowEvalResult(0.5);
    }
}
