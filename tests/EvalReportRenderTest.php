<?php

declare(strict_types=1);

namespace AiWorkflow\Tests;

use AiWorkflow\Eval\EvalReportRenderer;
use AiWorkflow\Models\AiWorkflowEvalRun;
use AiWorkflow\Models\AiWorkflowEvalScore;
use AiWorkflow\Models\AiWorkflowRequest;
use AiWorkflow\Tests\Fixtures\StubReviewContext;
use Illuminate\Support\Facades\File;

class EvalReportRenderTest extends DatabaseTestCase
{
    public function test_it_renders_a_self_contained_page(): void
    {
        // A resolver is the only source of external URLs on the page, so the
        // assertions below check nothing unless one is configured.
        config(['ai-workflow.review.context' => StubReviewContext::class]);

        $run = $this->seedRun();

        $html = app(EvalReportRenderer::class)->render($run);

        $this->assertStringContainsString('https://example.test/issues/', $html);

        $this->assertStringContainsString('<!DOCTYPE html>', $html);
        $this->assertStringContainsString('Comparison run', $html);
        $this->assertStringContainsString('Model comparison', $html);
        $this->assertStringContainsString('Confusion matrix', $html);
        $this->assertStringContainsString('openrouter:a', $html);
        $this->assertStringContainsString('openrouter:b', $html);

        // Self-contained means the browser fetches nothing when the file is
        // opened offline. The link asserted above is inert until someone clicks
        // it, so it does not break that.
        $this->assertStringNotContainsString('<script src=', $html);
        $this->assertStringNotContainsString('<link rel=', $html);
        $this->assertStringNotContainsString('src="http', $html);
        $this->assertStringNotContainsString('url(http', $html);
        $this->assertStringNotContainsString('@import', $html);
    }

    public function test_it_shows_accuracy_and_the_baseline_delta(): void
    {
        $run = $this->seedRun();

        $html = app(EvalReportRenderer::class)->render($run);

        // Model A got 4/4, model B 3/4.
        $this->assertStringContainsString('100.0%', $html);
        $this->assertStringContainsString('75.0%', $html);
        $this->assertStringContainsString('baseline', $html);
        $this->assertStringContainsString('-25.0 pp', $html);

        // On four samples the Wilson intervals overlap, and the report flags it.
        $this->assertStringContainsString('CI overlaps baseline', $html);

        // The paired test column: B lost its single disagreement with A, so
        // the exact McNemar p is 1.000 over a 0–1 split.
        $this->assertStringContainsString('p (McNemar)', $html);
        $this->assertStringContainsString('1.000 (0–1)', $html);
    }

    public function test_it_reports_thought_tokens(): void
    {
        $run = $this->seedRun(thoughtTokens: 216);

        $html = app(EvalReportRenderer::class)->render($run);

        // Four scored requests per model, so the per-model total is 864.
        $this->assertStringContainsString('/ 864 thought tokens', $html);

        // A directive glued to a word character is left uncompiled by Blade,
        // which puts the condition itself on the page.
        $this->assertStringNotContainsString('@if', $html);
        $this->assertStringNotContainsString('@endif', $html);
    }

    public function test_it_omits_thought_tokens_when_a_model_reported_none(): void
    {
        $run = $this->seedRun();

        $html = app(EvalReportRenderer::class)->render($run);

        // The test above would still pass if the renderer emitted the
        // thought-token line unconditionally.
        $this->assertStringNotContainsString('thought tokens', $html);
    }

    public function test_it_shows_the_host_apps_context_for_each_decision(): void
    {
        config(['ai-workflow.review.context' => StubReviewContext::class]);

        $run = $this->seedRun();

        $html = app(EvalReportRenderer::class)->render($run);

        // Whoever reads a wrong answer needs the ticket it was about and the
        // last comment on it. Without those, the drill-down is a label and a
        // number.
        $this->assertStringContainsString('GitHub #4821', $html);
        $this->assertStringContainsString('Last comment before this decision', $html);
        $this->assertStringContainsString('The import failed again overnight.', $html);
        $this->assertStringContainsString('<a href="https://example.test/issues/', $html);

        // Blade leaves a directive glued to a word character uncompiled, which
        // is how the thought-token line broke.
        $this->assertStringNotContainsString('@if', $html);
        $this->assertStringNotContainsString('@foreach', $html);
        $this->assertStringNotContainsString('@endif', $html);
    }

    public function test_it_renders_without_context_when_the_host_app_configures_none(): void
    {
        config(['ai-workflow.review.context' => null]);

        $run = $this->seedRun();

        $html = app(EvalReportRenderer::class)->render($run);

        $this->assertStringContainsString('Decisions', $html);
        $this->assertStringNotContainsString('Last comment before this decision', $html);
    }

    public function test_it_flags_a_decision_every_model_chose_against(): void
    {
        $run = AiWorkflowEvalRun::create(['name' => 'Disagreement run', 'models' => ['openrouter:a', 'openrouter:b']]);

        $agreed = $this->seedDecision($run, truth: 'respond', predictions: ['respond', 'respond']);
        $against = $this->seedDecision($run, truth: 'close', predictions: ['wait', 'wait']);
        $split = $this->seedDecision($run, truth: 'chase', predictions: ['chase', 'wait']);

        $html = app(EvalReportRenderer::class)->render($run);

        $flagged = $this->summaryFor($html, $against);
        $this->assertStringContainsString('all disagree', $flagged);

        // A decision one model got right is a model gap, not a reason to
        // re-read the label, so it must not carry the same flag.
        $this->assertStringNotContainsString('all disagree', $this->summaryFor($html, $split));
        $this->assertStringNotContainsString('all disagree', $this->summaryFor($html, $agreed));

        // The two flags answer different questions and can both apply.
        $this->assertStringContainsString('disputed', $this->summaryFor($html, $split));
    }

    public function test_it_does_not_flag_an_unlabelled_decision(): void
    {
        $run = AiWorkflowEvalRun::create(['name' => 'Unlabelled run', 'models' => ['openrouter:a']]);
        $id = $this->seedDecision($run, truth: null, predictions: ['respond']);

        $html = app(EvalReportRenderer::class)->render($run);

        // With no label there is nothing to disagree with.
        $this->assertStringNotContainsString('all disagree', $this->summaryFor($html, $id));
    }

    /**
     * @param  list<string>  $predictions  One per model, in the run's order.
     */
    private function seedDecision(AiWorkflowEvalRun $run, ?string $truth, array $predictions): int
    {
        $request = AiWorkflowRequest::create([
            'prompt_id' => 'decide_next_action',
            'method' => 'sendStructuredMessages',
            'provider' => 'openrouter',
            'model' => 'test-model',
            'system_prompt' => 'Decide.',
            'messages' => [['type' => 'user', 'content' => 'Ticket body']],
            'finish_reason' => 'stop',
            'duration_ms' => 100,
        ]);

        foreach ($predictions as $index => $predicted) {
            AiWorkflowEvalScore::create([
                'eval_run_id' => $run->id,
                'request_id' => $request->id,
                'model' => ['openrouter:a', 'openrouter:b'][$index],
                'score' => $predicted === $truth ? 1.0 : 0.0,
                'ground_truth' => $truth,
                'predicted' => $predicted,
                'input_tokens' => 10,
                'output_tokens' => 20,
                'duration_ms' => 100,
            ]);
        }

        return $request->id;
    }

    /**
     * The one decision's summary line, so a flag on a sibling cannot satisfy
     * an assertion about this one.
     */
    private function summaryFor(string $html, int $requestId): string
    {
        preg_match('/<summary>\s*#'.$requestId.'\b.*?<\/summary>/s', $html, $matches);

        return $matches[0] ?? '';
    }

    public function test_it_warns_when_a_model_mostly_failed(): void
    {
        // A model that could not be called at all scores 0%, which reads as
        // "terrible model" unless the report says otherwise.
        $run = AiWorkflowEvalRun::create(['name' => 'Broken model', 'models' => ['openrouter:a']]);

        foreach (range(1, 4) as $i) {
            $request = AiWorkflowRequest::create([
                'prompt_id' => 'decide_next_action',
                'method' => 'sendStructuredMessages',
                'provider' => 'openrouter',
                'model' => 'test-model',
                'system_prompt' => 'Decide.',
                'messages' => [['type' => 'user', 'content' => "Ticket {$i}"]],
                'finish_reason' => 'stop',
                'duration_ms' => 100,
            ]);

            AiWorkflowEvalScore::create([
                'eval_run_id' => $run->id,
                'request_id' => $request->id,
                'model' => 'openrouter:a',
                'score' => 0.0,
                'details' => ['error' => 'Extra inputs are not permitted'],
                'ground_truth' => 'respond',
            ]);
        }

        $html = app(EvalReportRenderer::class)->render($run);

        $this->assertStringContainsString('failed on 4 of 4 items', $html);
        $this->assertStringContainsString('not the quality of its answers', $html);
    }

    public function test_it_warns_when_pricing_is_missing(): void
    {
        $run = $this->seedRun();

        $html = app(EvalReportRenderer::class)->render($run);

        $this->assertStringContainsString('No pricing configured', $html);
    }

    public function test_it_escapes_content_from_the_prompt(): void
    {
        $run = AiWorkflowEvalRun::create(['name' => 'Escaping run', 'models' => ['openrouter:a']]);

        $request = AiWorkflowRequest::create([
            'prompt_id' => 'decide_next_action',
            'method' => 'sendStructuredMessages',
            'provider' => 'openrouter',
            'model' => 'test-model',
            'system_prompt' => 'Decide.',
            'messages' => [['type' => 'user', 'content' => 'Ticket <script>alert("xss")</script> body']],
            'finish_reason' => 'stop',
            'duration_ms' => 100,
        ]);

        AiWorkflowEvalScore::create([
            'eval_run_id' => $run->id,
            'request_id' => $request->id,
            'model' => 'openrouter:a',
            'score' => 1.0,
            'ground_truth' => 'respond',
            'predicted' => 'respond',
            'input_tokens' => 10,
            'output_tokens' => 20,
            'duration_ms' => 100,
        ]);

        $html = app(EvalReportRenderer::class)->render($run);

        $this->assertStringNotContainsString('<script>alert', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_the_command_writes_a_report_file(): void
    {
        $run = $this->seedRun();
        $path = storage_path('app/eval/custom-report.html');

        $this->artisan('eval:report', ['run' => $run->id, '--out' => $path])
            ->assertSuccessful();

        $this->assertTrue(File::exists($path));
        $this->assertStringContainsString('Model comparison', File::get($path));

        File::delete($path);
    }

    public function test_the_default_report_path_includes_the_run_id(): void
    {
        // Two runs can share a name; the id keeps their reports apart.
        $run = $this->seedRun();
        $path = storage_path("app/eval/comparison-run-{$run->id}.html");

        $this->artisan('eval:report', ['run' => $run->id])
            ->assertSuccessful();

        $this->assertTrue(File::exists($path));

        File::delete($path);
    }

    public function test_the_command_rejects_an_unknown_baseline(): void
    {
        $run = $this->seedRun();

        $this->artisan('eval:report', ['run' => $run->id, '--baseline' => 'openrouter:nope'])
            ->expectsOutputToContain('not part of this run')
            ->assertFailed();
    }

    public function test_the_command_resolves_a_run_by_name(): void
    {
        $this->seedRun();
        $path = storage_path('app/eval/by-name.html');

        $this->artisan('eval:report', ['run' => 'Comparison', '--out' => $path])
            ->assertSuccessful();

        $this->assertTrue(File::exists($path));

        File::delete($path);
    }

    public function test_the_command_fails_on_an_unknown_run(): void
    {
        $this->artisan('eval:report', ['run' => 'nothing-like-this'])
            ->expectsOutputToContain('No eval run matching')
            ->assertFailed();
    }

    private function seedRun(?int $thoughtTokens = null): AiWorkflowEvalRun
    {
        $run = AiWorkflowEvalRun::create([
            'name' => 'Comparison run',
            'models' => ['openrouter:a', 'openrouter:b'],
        ]);

        $truths = ['respond', 'respond', 'close', 'wait'];
        $modelB = ['respond', 'close', 'close', 'wait'];

        foreach ($truths as $index => $truth) {
            $request = AiWorkflowRequest::create([
                'prompt_id' => 'decide_next_action',
                'method' => 'sendStructuredMessages',
                'provider' => 'openrouter',
                'model' => 'test-model',
                'system_prompt' => 'Decide.',
                'messages' => [['type' => 'user', 'content' => "Ticket body {$index}"]],
                'finish_reason' => 'stop',
                'duration_ms' => 100,
            ]);

            foreach ([['openrouter:a', $truth], ['openrouter:b', $modelB[$index]]] as [$model, $predicted]) {
                AiWorkflowEvalScore::create([
                    'eval_run_id' => $run->id,
                    'request_id' => $request->id,
                    'model' => $model,
                    'score' => $truth === $predicted ? 1.0 : 0.0,
                    'ground_truth' => $truth,
                    'predicted' => $predicted,
                    'input_tokens' => 100,
                    'output_tokens' => 200,
                    'thought_tokens' => $thoughtTokens,
                    'duration_ms' => 100,
                ]);
            }
        }

        return $run;
    }
}
