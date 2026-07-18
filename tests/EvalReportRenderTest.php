<?php

declare(strict_types=1);

namespace AiWorkflow\Tests;

use AiWorkflow\Eval\EvalReportRenderer;
use AiWorkflow\Models\AiWorkflowEvalRun;
use AiWorkflow\Models\AiWorkflowEvalScore;
use AiWorkflow\Models\AiWorkflowRequest;
use Illuminate\Support\Facades\File;

class EvalReportRenderTest extends DatabaseTestCase
{
    public function test_it_renders_a_self_contained_page(): void
    {
        $run = $this->seedRun();

        $html = app(EvalReportRenderer::class)->render($run);

        $this->assertStringContainsString('<!DOCTYPE html>', $html);
        $this->assertStringContainsString('Comparison run', $html);
        $this->assertStringContainsString('Model comparison', $html);
        $this->assertStringContainsString('Confusion matrix', $html);
        $this->assertStringContainsString('openrouter:a', $html);
        $this->assertStringContainsString('openrouter:b', $html);

        // Self-contained: nothing to fetch when the file is opened offline.
        $this->assertStringNotContainsString('<script src=', $html);
        $this->assertStringNotContainsString('<link rel="stylesheet"', $html);
        $this->assertStringNotContainsString('http://', $html);
        $this->assertStringNotContainsString('https://', $html);
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

    private function seedRun(): AiWorkflowEvalRun
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
                    'duration_ms' => 100,
                ]);
            }
        }

        return $run;
    }
}
