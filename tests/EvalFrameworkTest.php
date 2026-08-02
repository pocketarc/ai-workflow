<?php

declare(strict_types=1);

namespace AiWorkflow\Tests;

use AiWorkflow\AiWorkflowReplayer;
use AiWorkflow\Eval\AiJudge;
use AiWorkflow\Eval\AiWorkflowEvalJudge;
use AiWorkflow\Eval\AiWorkflowEvalResult;
use AiWorkflow\Eval\AiWorkflowEvalRunner;
use AiWorkflow\Models\AiWorkflowEvalRun;
use AiWorkflow\Models\AiWorkflowEvalScore;
use AiWorkflow\Models\AiWorkflowRequest;
use Illuminate\Database\Eloquent\JsonEncodingException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Mockery\MockInterface;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Exceptions\PrismException;
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
        $this->assertEqualsWithDelta(0.9, (float) $scoreA->score, 0.0001);
        $this->assertSame('Hello world', $scoreA->response_text);
    }

    public function test_eval_runner_replays_against_todays_prompt(): void
    {
        $request = AiWorkflowRequest::create([
            'prompt_id' => 'test_prompt',
            'method' => 'sendMessages',
            'provider' => 'openrouter',
            'model' => 'test-model',
            'system_prompt' => 'The prompt as it read months ago.',
            'messages' => [['type' => 'user', 'content' => 'Hello']],
            'finish_reason' => 'stop',
            'duration_ms' => 100,
        ]);

        $fake = Prism::fake([
            TextResponseFake::make()->withText('Replayed')->withFinishReason(FinishReason::Stop),
        ]);

        app(AiWorkflowEvalRunner::class)->run(
            name: 'Prompt regression',
            requests: [$request],
            models: ['openrouter:model-a'],
            judge: $this->alwaysScoreJudge(1.0),
        );

        // Re-running the golden set after editing a prompt is the regression
        // test this framework exists for, so replaying the recorded text would
        // score a prompt nobody is using any more.
        $fake->assertRequest(function (array $requests): void {
            $this->assertSame('You are a helpful test assistant.', $requests[0]->systemPrompts()[0]->content);
        });
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

    public function test_eval_runner_records_replay_usage_and_latency(): void
    {
        Prism::fake([
            StructuredResponseFake::make()
                ->withStructured(['intent' => 'billing'])
                ->withUsage(new Usage(15, 25))
                ->withFinishReason(FinishReason::Stop),
        ]);

        $request = $this->createStructuredRequest(['intent' => 'billing']);

        $runner = app(AiWorkflowEvalRunner::class);
        $evalRun = $runner->run(
            name: 'Usage eval',
            requests: [$request],
            models: ['openrouter:model-a'],
            judge: $this->alwaysScoreJudge(1.0),
        );

        $score = $evalRun->scores->first();
        $this->assertNotNull($score);
        $this->assertSame(15, $score->input_tokens);
        $this->assertSame(25, $score->output_tokens);
        $this->assertNotNull($score->duration_ms);
        $this->assertGreaterThanOrEqual(0, $score->duration_ms);
    }

    public function test_a_judge_failure_still_persists_the_replay_usage(): void
    {
        Prism::fake([
            StructuredResponseFake::make()
                ->withStructured(['intent' => 'billing'])
                ->withUsage(new Usage(15, 25, thoughtTokens: 5))
                ->withFinishReason(FinishReason::Stop),
        ]);

        $request = $this->createStructuredRequest(['intent' => 'billing']);

        $judge = new class implements AiWorkflowEvalJudge
        {
            public function judge(AiWorkflowRequest $originalRequest, Response|StructuredResponse $response): AiWorkflowEvalResult
            {
                throw new InvalidArgumentException('judge exploded');
            }
        };

        $evalRun = app(AiWorkflowEvalRunner::class)->run(
            name: 'Judge failure eval',
            requests: [$request],
            models: ['openrouter:model-a'],
            judge: $judge,
        );

        // The replay succeeded and was paid for; the judge failing must not
        // erase the replay's usage from the cost accounting.
        $score = $evalRun->scores->first();
        $this->assertNotNull($score);
        $this->assertSame('judge exploded', $score->details['error'] ?? null);
        $this->assertSame(15, $score->input_tokens);
        $this->assertSame(25, $score->output_tokens);
        $this->assertSame(5, $score->thought_tokens);
        $this->assertNotNull($score->duration_ms);
    }

    public function test_eval_runner_persists_each_score_as_it_goes(): void
    {
        // A real run is hours of paid API calls. If results only landed at the
        // end, an interrupted run would throw away work already billed for — so
        // each score must be written as soon as it exists.
        Prism::fake([
            TextResponseFake::make()->withText('one')->withFinishReason(FinishReason::Stop),
            TextResponseFake::make()->withText('two')->withFinishReason(FinishReason::Stop),
            TextResponseFake::make()->withText('three')->withFinishReason(FinishReason::Stop),
        ]);

        $requests = [$this->createTextRequest(), $this->createTextRequest(), $this->createTextRequest()];

        $baseLevel = DB::connection()->transactionLevel();

        $seen = [];
        $levels = [];
        $judge = new class($seen, $levels) implements AiWorkflowEvalJudge
        {
            /**
             * @param  list<int>  $seen
             * @param  list<int>  $levels
             */
            public function __construct(private array &$seen, private array &$levels) {}

            public function judge(AiWorkflowRequest $originalRequest, Response|StructuredResponse $response): AiWorkflowEvalResult
            {
                // How many scores are already visible at this point, and
                // whether the runner has opened a transaction of its own.
                $this->seen[] = AiWorkflowEvalScore::query()->count();
                $this->levels[] = DB::connection()->transactionLevel();

                return new AiWorkflowEvalResult(1.0);
            }
        };

        app(AiWorkflowEvalRunner::class)->run(
            name: 'Incremental',
            requests: $requests,
            models: ['openrouter:model-a'],
            judge: $judge,
        );

        // Growing counts prove scores land one at a time, not in a final
        // flush. Counts alone can't prove commits — this connection would see
        // its own uncommitted rows through a run-wide transaction too — so the
        // unchanged transaction level closes that gap: nothing between these
        // writes and RefreshDatabase's wrapper holds them back.
        $this->assertSame([0, 1, 2], $seen);
        $this->assertSame([$baseLevel, $baseLevel, $baseLevel], $levels);
        $this->assertSame(3, AiWorkflowEvalScore::query()->count());
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

    public function test_eval_runner_retries_a_replay_the_provider_fumbled(): void
    {
        $attempts = 0;

        $this->mock(AiWorkflowReplayer::class, function (MockInterface $mock) use (&$attempts): void {
            $mock->shouldReceive('replay')->twice()->andReturnUsing(function () use (&$attempts): Response {
                $attempts++;

                // A response the provider could not classify. Generation is
                // non-deterministic, so the next sample usually lands.
                if ($attempts === 1) {
                    throw new PrismException('OpenRouter: unknown finish reason');
                }

                return $this->makeTextResponse('Second time lucky');
            });
        });

        $evalRun = app(AiWorkflowEvalRunner::class)->run(
            name: 'Retry eval',
            requests: [$this->createTextRequest()],
            models: ['openrouter:model-a'],
            judge: $this->alwaysScoreJudge(1.0),
        );

        $score = $evalRun->scores->first();
        $this->assertNotNull($score);
        $this->assertSame('Second time lucky', $score->response_text);
        $this->assertNull($score->details['error'] ?? null);
    }

    public function test_eval_runner_gives_up_after_the_configured_attempts(): void
    {
        $this->mock(AiWorkflowReplayer::class, function (MockInterface $mock): void {
            $mock->shouldReceive('replay')->twice()->andThrow(new PrismException('OpenRouter: unknown finish reason'));
        });

        $evalRun = app(AiWorkflowEvalRunner::class)->run(
            name: 'Exhausted eval',
            requests: [$this->createTextRequest()],
            models: ['openrouter:model-a'],
            judge: $this->alwaysScoreJudge(1.0),
        );

        $score = $evalRun->scores->first();
        $this->assertNotNull($score);
        $this->assertSame(0.0, (float) $score->score);
        $this->assertStringContainsString('unknown finish reason', (string) ($score->details['error'] ?? ''));
    }

    public function test_eval_runner_does_not_retry_a_request_the_provider_rejected(): void
    {
        $rejected = new PrismException('OpenRouter: No allowed providers are available for the selected model.');
        $rejected->httpStatus = 404;

        // A 4xx fails the same way every time, so a second attempt only spends
        // money to reach the same score.
        $this->mock(AiWorkflowReplayer::class, function (MockInterface $mock) use ($rejected): void {
            $mock->shouldReceive('replay')->once()->andThrow($rejected);
        });

        $evalRun = app(AiWorkflowEvalRunner::class)->run(
            name: 'Rejected eval',
            requests: [$this->createTextRequest()],
            models: ['openrouter:model-a'],
            judge: $this->alwaysScoreJudge(1.0),
        );

        $this->assertSame(0.0, (float) ($evalRun->scores->first()?->score ?? -1.0));
    }

    public function test_eval_runner_handles_partial_failure(): void
    {
        Prism::fake([
            TextResponseFake::make()
                ->withText('Good response')
                ->withFinishReason(FinishReason::Stop),
            TextResponseFake::make()
                ->withText('Good response')
                ->withFinishReason(FinishReason::Stop),
        ]);

        $request = $this->createTextRequest(responseText: 'Original');

        $callCount = 0;
        $judge = new class($callCount) implements AiWorkflowEvalJudge
        {
            public function __construct(private int &$callCount) {}

            public function judge(AiWorkflowRequest $originalRequest, Response|StructuredResponse $response): AiWorkflowEvalResult
            {
                $this->callCount++;
                if ($this->callCount === 1) {
                    throw new \RuntimeException('Judge exploded');
                }

                return new AiWorkflowEvalResult(0.8);
            }
        };

        $runner = app(AiWorkflowEvalRunner::class);
        $evalRun = $runner->run(
            name: 'Partial failure eval',
            requests: [$request],
            models: ['openrouter:model-a', 'openrouter:model-b'],
            judge: $judge,
        );

        // Both scores created — first failed with 0.0, second succeeded
        $this->assertCount(2, $evalRun->scores);

        $scoreA = $evalRun->scores->where('model', 'openrouter:model-a')->first();
        $this->assertNotNull($scoreA);
        $this->assertEqualsWithDelta(0.0, (float) $scoreA->score, 0.0001);
        $this->assertSame('Judge exploded', $scoreA->details['error'] ?? null);

        $scoreB = $evalRun->scores->where('model', 'openrouter:model-b')->first();
        $this->assertNotNull($scoreB);
        $this->assertEqualsWithDelta(0.8, (float) $scoreB->score, 0.0001);
    }

    public function test_eval_runner_surfaces_score_persistence_failures(): void
    {
        Prism::fake([
            TextResponseFake::make()
                ->withText('Good response')
                ->withFinishReason(FinishReason::Stop),
        ]);

        $request = $this->createTextRequest();

        // INF can't be JSON-encoded, so persisting this (successful) result
        // throws — a storage fault, not a replay/judge one.
        $judge = $this->alwaysScoreJudge(1.0, ['confidence' => INF]);

        try {
            app(AiWorkflowEvalRunner::class)->run(
                name: 'Persistence failure',
                requests: [$request],
                models: ['openrouter:model-a'],
                judge: $judge,
            );
            $this->fail('Expected the persistence failure to surface.');
        } catch (JsonEncodingException) {
        }

        // The failure surfaced instead of being rewritten as a zero-score
        // "replay failed" row for a pair that actually succeeded.
        $this->assertSame(0, AiWorkflowEvalScore::query()->count());
    }

    public function test_eval_result_enforces_the_score_range(): void
    {
        foreach ([-0.1, 1.1, NAN, INF, -INF] as $score) {
            try {
                new AiWorkflowEvalResult($score);
                $this->fail("Expected score {$score} to be rejected.");
            } catch (InvalidArgumentException) {
            }
        }

        // The bounds themselves are valid scores.
        $this->assertSame(0.0, (new AiWorkflowEvalResult(0.0))->score);
        $this->assertSame(1.0, (new AiWorkflowEvalResult(1.0))->score);
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
        $this->assertEqualsWithDelta(0.75, (float) $score->score, 0.0001);
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
        ]);

        AiWorkflowEvalScore::create([
            'eval_run_id' => $evalRun->id,
            'request_id' => $request->id,
            'model' => 'model-b',
            'score' => 0.4,
        ]);

        $this->assertEqualsWithDelta(0.6, $evalRun->averageScore(), 0.001);
        $this->assertEqualsWithDelta(0.8, $evalRun->averageScoreForModel('model-a'), 0.001);
        $this->assertEqualsWithDelta(0.4, $evalRun->averageScoreForModel('model-b'), 0.001);
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
