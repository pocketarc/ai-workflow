<?php

declare(strict_types=1);

namespace AiWorkflow\Tests;

use AiWorkflow\Enums\AnnotationVerdict;
use AiWorkflow\Eval\GoldenSetAssembler;
use AiWorkflow\Models\AiWorkflowAnnotation;
use AiWorkflow\Models\AiWorkflowEvalScore;
use AiWorkflow\Models\AiWorkflowRequest;
use AiWorkflow\Tests\Fixtures\RecordsGroundTruthJudge;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\StructuredResponseFake;
use Prism\Prism\ValueObjects\Usage;

class GoldenSetTest extends DatabaseTestCase
{
    public function test_it_collects_thumbs_up_requests_with_their_label(): void
    {
        $request = $this->makeRequest();
        AiWorkflowAnnotation::create([
            'request_id' => $request->id,
            'verdict' => AnnotationVerdict::Up,
            'label' => 'respond_to_customer',
        ]);

        $golden = app(GoldenSetAssembler::class)->assemble();

        $this->assertCount(1, $golden);
        $this->assertSame($request->id, $golden[0]->id);
        $this->assertSame(
            'respond_to_customer',
            $golden[0]->getAttribute(AiWorkflowRequest::GROUND_TRUTH_ATTRIBUTE),
        );
    }

    public function test_it_excludes_rejections_that_name_no_answer(): void
    {
        $request = $this->makeRequest();
        AiWorkflowAnnotation::create([
            'request_id' => $request->id,
            'verdict' => AnnotationVerdict::Down,
            'reason' => 'Should have waited.',
        ]);

        // Knowing a decision was wrong gives nothing to score against.
        $this->assertSame([], app(GoldenSetAssembler::class)->assemble());
    }

    public function test_it_includes_a_rejection_that_supplies_the_right_answer(): void
    {
        $request = $this->makeRequest();
        AiWorkflowAnnotation::create([
            'request_id' => $request->id,
            'verdict' => AnnotationVerdict::Down,
            'label' => 'wait_for_development_team',
            'reason' => 'Dev work outstanding.',
        ]);

        // A correction is the most valuable kind of entry: a case the model is
        // known to get wrong, with the answer it should have given.
        $golden = app(GoldenSetAssembler::class)->assemble();

        $this->assertCount(1, $golden);
        $this->assertSame(
            'wait_for_development_team',
            $golden[0]->getAttribute(AiWorkflowRequest::GROUND_TRUTH_ATTRIBUTE),
        );
    }

    public function test_a_verdict_filter_narrows_the_set(): void
    {
        $approved = $this->makeRequest();
        AiWorkflowAnnotation::create([
            'request_id' => $approved->id,
            'verdict' => AnnotationVerdict::Up,
            'label' => 'respond_to_customer',
        ]);

        $corrected = $this->makeRequest();
        AiWorkflowAnnotation::create([
            'request_id' => $corrected->id,
            'verdict' => AnnotationVerdict::Down,
            'label' => 'close_ticket',
        ]);

        $this->assertCount(2, app(GoldenSetAssembler::class)->assemble());
        $this->assertCount(1, app(GoldenSetAssembler::class)->assemble(verdict: AnnotationVerdict::Up));
        $this->assertCount(1, app(GoldenSetAssembler::class)->assemble(verdict: AnnotationVerdict::Down));
    }

    public function test_a_relabelled_request_uses_its_latest_verdict(): void
    {
        // Approved, then corrected to a thumbs-down — it must leave the set.
        $request = $this->makeRequest();
        AiWorkflowAnnotation::create([
            'request_id' => $request->id,
            'verdict' => AnnotationVerdict::Up,
            'label' => 'close_ticket',
        ]);
        AiWorkflowAnnotation::create([
            'request_id' => $request->id,
            'verdict' => AnnotationVerdict::Down,
        ]);

        $this->assertSame([], app(GoldenSetAssembler::class)->assemble());
    }

    public function test_it_filters_by_prompt_and_respects_the_limit(): void
    {
        $decide = $this->makeRequest(promptId: 'decide_next_action');
        $other = $this->makeRequest(promptId: 'summarise');

        foreach ([$decide, $other] as $request) {
            AiWorkflowAnnotation::create([
                'request_id' => $request->id,
                'verdict' => AnnotationVerdict::Up,
                'label' => 'respond_to_customer',
            ]);
        }

        $golden = app(GoldenSetAssembler::class)->assemble(promptId: 'decide_next_action');

        $this->assertCount(1, $golden);
        $this->assertSame($decide->id, $golden[0]->id);

        $extra = $this->makeRequest(promptId: 'decide_next_action');
        AiWorkflowAnnotation::create([
            'request_id' => $extra->id,
            'verdict' => AnnotationVerdict::Up,
            'label' => 'close_ticket',
        ]);

        $limited = app(GoldenSetAssembler::class)->assemble(promptId: 'decide_next_action', limit: 1);
        $this->assertCount(1, $limited);
    }

    public function test_eval_run_from_annotations_scores_against_the_human_label(): void
    {
        Prism::fake([
            StructuredResponseFake::make()
                ->withStructured(['close_ticket' => ['likelihood' => 90]])
                ->withUsage(new Usage(11, 22, thoughtTokens: 7))
                ->withFinishReason(FinishReason::Stop),
        ]);

        $request = $this->makeRequest(promptId: 'decide_next_action');
        AiWorkflowAnnotation::create([
            'request_id' => $request->id,
            'verdict' => AnnotationVerdict::Up,
            'label' => 'respond_to_customer',
        ]);

        $this->artisan('eval:run', [
            '--from-annotations' => true,
            '--prompt' => 'decide_next_action',
            '--judge' => RecordsGroundTruthJudge::class,
            '--models' => 'openrouter:model-a',
        ])->assertSuccessful();

        $score = AiWorkflowEvalScore::query()->firstOrFail();

        // The human label reached the judge and was persisted for the report.
        $this->assertSame('respond_to_customer', $score->ground_truth);
        $this->assertSame('close_ticket', $score->predicted);
        $this->assertSame(11, $score->input_tokens);
        $this->assertSame(22, $score->output_tokens);
        $this->assertSame(7, $score->thought_tokens);
    }

    public function test_eval_run_from_annotations_reports_an_empty_set(): void
    {
        $this->artisan('eval:run', [
            '--from-annotations' => true,
            '--judge' => RecordsGroundTruthJudge::class,
            '--models' => 'openrouter:model-a',
        ])
            ->expectsOutputToContain('No requests found')
            ->assertSuccessful();
    }

    public function test_eval_run_rejects_an_unknown_verdict(): void
    {
        $this->artisan('eval:run', [
            '--from-annotations' => true,
            '--verdict' => 'sideways',
            '--judge' => RecordsGroundTruthJudge::class,
            '--models' => 'openrouter:model-a',
        ])
            ->expectsOutputToContain("Unknown verdict 'sideways'")
            ->assertFailed();
    }

    private function makeRequest(string $promptId = 'decide_next_action'): AiWorkflowRequest
    {
        return AiWorkflowRequest::create([
            'prompt_id' => $promptId,
            'method' => 'sendStructuredMessages',
            'provider' => 'openrouter',
            'model' => 'test-model',
            'system_prompt' => 'Decide.',
            'messages' => [['type' => 'user', 'content' => 'Ticket body']],
            'structured_response' => ['respond_to_customer' => ['likelihood' => 80, 'reasoning' => 'x']],
            'finish_reason' => 'stop',
            'duration_ms' => 100,
            'schema' => [
                'type' => 'object',
                'properties' => ['close_ticket' => ['type' => 'object', 'description' => 'Close']],
                'required' => [],
            ],
        ]);
    }
}
