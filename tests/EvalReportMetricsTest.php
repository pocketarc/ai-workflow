<?php

declare(strict_types=1);

namespace AiWorkflow\Tests;

use AiWorkflow\Eval\EvalReportMetrics;
use AiWorkflow\Eval\EvalReportModelSummary;
use AiWorkflow\Models\AiWorkflowEvalRun;
use AiWorkflow\Models\AiWorkflowEvalScore;
use AiWorkflow\Models\AiWorkflowRequest;

class EvalReportMetricsTest extends DatabaseTestCase
{
    /** @var list<int> */
    private array $requestIds = [];

    public function test_it_computes_accuracy_and_ranks_models(): void
    {
        $run = $this->seedRun();

        $report = app(EvalReportMetrics::class)->compute($run);

        $this->assertSame(4, $report->requestCount);
        $this->assertSame(4, $report->labelledCount);
        $this->assertFalse($report->hasUnlabelledRequests());

        // Best accuracy sorts first.
        $best = $report->best();
        $this->assertNotNull($best);
        $this->assertSame('openrouter:a', $best->model);
        $this->assertEqualsWithDelta(1.0, $best->accuracy ?? 0.0, 0.0001);
        $this->assertSame(4, $best->correct);

        $b = $this->summaryFor($report->models, 'openrouter:b');
        $this->assertEqualsWithDelta(0.75, $b->accuracy ?? 0.0, 0.0001);
        $this->assertSame(3, $b->correct);
    }

    public function test_it_marks_the_baseline_and_reports_the_delta(): void
    {
        $run = $this->seedRun();

        $report = app(EvalReportMetrics::class)->compute($run);

        // The run's first configured model is the production baseline.
        $this->assertSame('openrouter:a', $report->baseline);

        $a = $this->summaryFor($report->models, 'openrouter:a');
        $this->assertTrue($a->isBaseline);
        $this->assertNull($a->accuracyDelta);

        $b = $this->summaryFor($report->models, 'openrouter:b');
        $this->assertFalse($b->isBaseline);
        $this->assertEqualsWithDelta(-0.25, $b->accuracyDelta ?? 0.0, 0.0001);

        // Four samples cannot separate 100% from 75%, so the report must say so.
        $this->assertTrue($b->overlapsBaselineInterval);
    }

    public function test_it_builds_a_confusion_matrix_and_per_class_scores(): void
    {
        $run = $this->seedRun();

        $report = app(EvalReportMetrics::class)->compute($run);
        $b = $this->summaryFor($report->models, 'openrouter:b');

        // Model B mistook one 'respond' for a 'close'.
        $this->assertSame(1, $b->confusion['respond']['respond'] ?? 0);
        $this->assertSame(1, $b->confusion['respond']['close'] ?? 0);

        // close: caught both real closes but over-predicted -> perfect recall, half precision.
        $this->assertEqualsWithDelta(0.5, $b->perClass['close']['precision'], 0.0001);
        $this->assertEqualsWithDelta(1.0, $b->perClass['close']['recall'], 0.0001);
        $this->assertEqualsWithDelta(2 / 3, $b->perClass['close']['f1'], 0.0001);

        // respond: never wrongly predicted, but missed one -> perfect precision, half recall.
        $this->assertEqualsWithDelta(1.0, $b->perClass['respond']['precision'], 0.0001);
        $this->assertEqualsWithDelta(0.5, $b->perClass['respond']['recall'], 0.0001);

        // macro-F1 averages the three ground-truth classes: (2/3 + 2/3 + 1) / 3.
        $this->assertEqualsWithDelta((2 / 3 + 2 / 3 + 1.0) / 3, $b->macroF1 ?? 0.0, 0.0001);
    }

    public function test_a_missing_prediction_counts_against_the_model(): void
    {
        $run = AiWorkflowEvalRun::create(['name' => 'Failure run', 'models' => ['openrouter:a']]);
        $requests = $this->makeRequests(2);

        $this->score($run, $requests[0], 'openrouter:a', 'respond', 'respond', 1.0);
        // The replay blew up: no prediction, but the item still counts.
        $this->score($run, $requests[1], 'openrouter:a', 'respond', null, 0.0, error: 'replay exploded');

        $report = app(EvalReportMetrics::class)->compute($run);
        $a = $this->summaryFor($report->models, 'openrouter:a');

        $this->assertSame(2, $a->labelled);
        $this->assertSame(1, $a->correct);
        $this->assertEqualsWithDelta(0.5, $a->accuracy ?? 0.0, 0.0001);
        $this->assertSame(1, $a->errors);

        // The failure is visible in the matrix rather than silently dropped.
        $this->assertSame(1, $a->confusion['respond'][EvalReportMetrics::NO_PREDICTION] ?? 0);
    }

    public function test_it_costs_a_run_from_configured_pricing(): void
    {
        config(['ai-workflow.model_pricing' => [
            'openrouter:a' => ['input' => 1.0, 'output' => 2.0],
        ]]);

        $run = $this->seedRun();
        $report = app(EvalReportMetrics::class)->compute($run);

        // 4 replays x (100 in, 200 out) = 400 input + 800 output tokens.
        $a = $this->summaryFor($report->models, 'openrouter:a');
        $this->assertSame(400, $a->inputTokens);
        $this->assertSame(800, $a->outputTokens);
        $this->assertEqualsWithDelta((400 / 1_000_000 * 1.0) + (800 / 1_000_000 * 2.0), $a->cost ?? 0.0, 1e-9);

        // Per-thousand and per-correct-decision derive from that total.
        $this->assertEqualsWithDelta(($a->cost ?? 0.0) / 4 * 1000, $a->costPerThousand() ?? 0.0, 1e-9);
        $this->assertEqualsWithDelta(($a->cost ?? 0.0) / 4, $a->costPerCorrectDecision() ?? 0.0, 1e-9);

        // Model B has no pricing, so it reports null rather than a wrong number.
        $b = $this->summaryFor($report->models, 'openrouter:b');
        $this->assertNull($b->cost);
        $this->assertContains('openrouter:b', $report->modelsMissingPricing);
    }

    public function test_it_reports_median_and_p95_latency(): void
    {
        $run = AiWorkflowEvalRun::create(['name' => 'Latency run', 'models' => ['openrouter:a']]);
        $requests = $this->makeRequests(4);

        foreach ([100, 100, 100, 5_000] as $index => $duration) {
            $this->score($run, $requests[$index], 'openrouter:a', 'respond', 'respond', 1.0, durationMs: $duration);
        }

        $report = app(EvalReportMetrics::class)->compute($run);
        $a = $this->summaryFor($report->models, 'openrouter:a');

        $this->assertEqualsWithDelta(100.0, $a->medianLatencyMs ?? 0.0, 0.0001);
        $this->assertGreaterThan(1_000.0, $a->p95LatencyMs ?? 0.0);
    }

    public function test_it_flags_an_unlabelled_golden_set(): void
    {
        $run = AiWorkflowEvalRun::create(['name' => 'Unlabelled', 'models' => ['openrouter:a']]);
        $requests = $this->makeRequests(2);

        $this->score($run, $requests[0], 'openrouter:a', 'respond', 'respond', 1.0);
        $this->score($run, $requests[1], 'openrouter:a', null, 'respond', 1.0);

        $report = app(EvalReportMetrics::class)->compute($run);

        $this->assertSame(2, $report->requestCount);
        $this->assertSame(1, $report->labelledCount);
        $this->assertTrue($report->hasUnlabelledRequests());
    }

    public function test_decisions_surface_disagreement_first(): void
    {
        $run = $this->seedRun();

        $report = app(EvalReportMetrics::class)->compute($run);

        $this->assertCount(4, $report->decisions);
        $this->assertFalse($report->isTruncated());

        // The one item the models disagreed on leads the drill-down.
        $this->assertTrue($report->decisions[0]->isContested());
    }

    public function test_decisions_can_be_truncated(): void
    {
        $run = $this->seedRun();

        $report = app(EvalReportMetrics::class)->compute($run, maxDecisions: 2);

        $this->assertCount(2, $report->decisions);
        $this->assertSame(4, $report->decisionsTotal);
        $this->assertTrue($report->isTruncated());
    }

    /**
     * Four labelled items; model A gets them all right, model B mistakes one
     * 'respond' for a 'close'.
     */
    private function seedRun(): AiWorkflowEvalRun
    {
        $run = AiWorkflowEvalRun::create([
            'name' => 'Comparison run',
            'models' => ['openrouter:a', 'openrouter:b'],
        ]);

        $requests = $this->makeRequests(4);
        $truths = ['respond', 'respond', 'close', 'wait'];
        $modelB = ['respond', 'close', 'close', 'wait'];

        foreach ($truths as $index => $truth) {
            $this->score($run, $requests[$index], 'openrouter:a', $truth, $truth, 1.0);
            $this->score($run, $requests[$index], 'openrouter:b', $truth, $modelB[$index], $truth === $modelB[$index] ? 1.0 : 0.0);
        }

        return $run;
    }

    /**
     * @return list<AiWorkflowRequest>
     */
    private function makeRequests(int $count): array
    {
        $requests = [];

        for ($i = 0; $i < $count; $i++) {
            $requests[] = AiWorkflowRequest::create([
                'prompt_id' => 'decide_next_action',
                'method' => 'sendStructuredMessages',
                'provider' => 'openrouter',
                'model' => 'test-model',
                'system_prompt' => 'Decide.',
                'messages' => [['type' => 'user', 'content' => "Ticket body {$i}"]],
                'finish_reason' => 'stop',
                'duration_ms' => 100,
            ]);
        }

        return $requests;
    }

    private function score(
        AiWorkflowEvalRun $run,
        AiWorkflowRequest $request,
        string $model,
        ?string $groundTruth,
        ?string $predicted,
        float $score,
        ?string $error = null,
        int $durationMs = 100,
    ): void {
        AiWorkflowEvalScore::create([
            'eval_run_id' => $run->id,
            'request_id' => $request->id,
            'model' => $model,
            'score' => $score,
            'details' => $error !== null ? ['error' => $error] : null,
            'ground_truth' => $groundTruth,
            'predicted' => $predicted,
            'input_tokens' => 100,
            'output_tokens' => 200,
            'duration_ms' => $durationMs,
        ]);
    }

    /**
     * @param  list<EvalReportModelSummary>  $summaries
     */
    private function summaryFor(array $summaries, string $model): EvalReportModelSummary
    {
        foreach ($summaries as $summary) {
            if ($summary->model === $model) {
                return $summary;
            }
        }

        $this->fail("No summary for model {$model}");
    }
}
