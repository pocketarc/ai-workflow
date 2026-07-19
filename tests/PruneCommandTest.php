<?php

declare(strict_types=1);

namespace AiWorkflow\Tests;

use AiWorkflow\Enums\AnnotationVerdict;
use AiWorkflow\Models\AiWorkflowAnnotation;
use AiWorkflow\Models\AiWorkflowEvalDataset;
use AiWorkflow\Models\AiWorkflowEvalDatasetEntry;
use AiWorkflow\Models\AiWorkflowEvalRun;
use AiWorkflow\Models\AiWorkflowEvalScore;
use AiWorkflow\Models\AiWorkflowExecution;
use AiWorkflow\Models\AiWorkflowRequest;
use RuntimeException;

class PruneCommandTest extends DatabaseTestCase
{
    public function test_it_prunes_requests_past_the_retention_window(): void
    {
        $old = $this->makeRequest(daysOld: 120);
        $recent = $this->makeRequest(daysOld: 5);

        $this->artisan('ai-workflow:prune')
            ->expectsOutputToContain('Pruned 1 request(s) older than 90 days.')
            ->assertSuccessful();

        $this->assertDatabaseMissing('ai_workflow_requests', ['id' => $old->id]);
        $this->assertDatabaseHas('ai_workflow_requests', ['id' => $recent->id]);
    }

    public function test_it_keeps_annotated_requests(): void
    {
        $request = $this->makeRequest(daysOld: 120);
        AiWorkflowAnnotation::create([
            'request_id' => $request->id,
            'verdict' => AnnotationVerdict::Up,
            'label' => 'respond_to_customer',
        ]);

        $this->artisan('ai-workflow:prune')->assertSuccessful();

        $this->assertDatabaseHas('ai_workflow_requests', ['id' => $request->id]);
    }

    public function test_it_keeps_scored_requests(): void
    {
        $request = $this->makeRequest(daysOld: 120);
        $run = AiWorkflowEvalRun::create([
            'name' => 'baseline',
            'models' => ['openrouter:model-a'],
        ]);
        AiWorkflowEvalScore::create([
            'eval_run_id' => $run->id,
            'request_id' => $request->id,
            'model' => 'openrouter:model-a',
            'score' => 1.0,
        ]);

        $this->artisan('ai-workflow:prune')->assertSuccessful();

        $this->assertDatabaseHas('ai_workflow_requests', ['id' => $request->id]);
    }

    public function test_it_keeps_requests_whose_execution_is_in_a_dataset(): void
    {
        $execution = $this->makeExecution(daysOld: 120);
        $request = $this->makeRequest(daysOld: 120, executionId: $execution->id);
        $this->addToDataset($execution);

        $this->artisan('ai-workflow:prune')->assertSuccessful();

        $this->assertDatabaseHas('ai_workflow_requests', ['id' => $request->id]);
        $this->assertDatabaseHas('ai_workflow_executions', ['id' => $execution->id]);
    }

    public function test_it_prunes_executions_only_once_they_are_empty(): void
    {
        $empty = $this->makeExecution(daysOld: 120);

        // This execution's request is old but annotated, so both survive.
        $annotated = $this->makeExecution(daysOld: 120);
        $request = $this->makeRequest(daysOld: 120, executionId: $annotated->id);
        AiWorkflowAnnotation::create([
            'request_id' => $request->id,
            'verdict' => AnnotationVerdict::Up,
            'label' => 'close_ticket',
        ]);

        // This one empties out during the same run once its request is pruned.
        $emptied = $this->makeExecution(daysOld: 120);
        $this->makeRequest(daysOld: 120, executionId: $emptied->id);

        $this->artisan('ai-workflow:prune')
            ->expectsOutputToContain('Pruned 2 empty execution(s) older than 90 days.')
            ->assertSuccessful();

        $this->assertDatabaseMissing('ai_workflow_executions', ['id' => $empty->id]);
        $this->assertDatabaseMissing('ai_workflow_executions', ['id' => $emptied->id]);
        $this->assertDatabaseHas('ai_workflow_executions', ['id' => $annotated->id]);
    }

    public function test_it_keeps_empty_dataset_member_executions(): void
    {
        // Deleting a dataset-member execution would cascade away the entry, so
        // even an empty one stays.
        $execution = $this->makeExecution(daysOld: 120);
        $this->addToDataset($execution);

        $this->artisan('ai-workflow:prune')->assertSuccessful();

        $this->assertDatabaseHas('ai_workflow_executions', ['id' => $execution->id]);
    }

    public function test_it_deletes_across_chunk_boundaries(): void
    {
        config(['ai-workflow.pruning.chunk_size' => 2]);

        foreach (range(1, 5) as $i) {
            $this->makeRequest(daysOld: 120);
        }

        $this->artisan('ai-workflow:prune')
            ->expectsOutputToContain('Pruned 5 request(s) older than 90 days.')
            ->assertSuccessful();

        $this->assertSame(0, AiWorkflowRequest::query()->count());
    }

    public function test_it_rejects_a_non_positive_retention_window(): void
    {
        // A negative window would put the cutoff in the future and delete
        // recent requests, so it must stop the command instead.
        config(['ai-workflow.pruning.requests_days' => -1]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid ai-workflow.pruning.requests_days (-1): must be a positive integer.');

        $this->artisan('ai-workflow:prune');
    }

    public function test_it_rejects_an_invalid_chunk_size(): void
    {
        config(['ai-workflow.pruning.chunk_size' => 0]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid ai-workflow.pruning.chunk_size (0): must be a positive integer.');

        $this->artisan('ai-workflow:prune');
    }

    public function test_the_retention_window_is_configurable(): void
    {
        config(['ai-workflow.pruning.requests_days' => 14]);

        $request = $this->makeRequest(daysOld: 30);

        $this->artisan('ai-workflow:prune')
            ->expectsOutputToContain('Pruned 1 request(s) older than 14 days.')
            ->assertSuccessful();

        $this->assertDatabaseMissing('ai_workflow_requests', ['id' => $request->id]);
    }

    private function makeRequest(int $daysOld, ?string $executionId = null): AiWorkflowRequest
    {
        $request = AiWorkflowRequest::create([
            'execution_id' => $executionId,
            'prompt_id' => 'decide_next_action',
            'method' => 'sendStructuredMessages',
            'provider' => 'openrouter',
            'model' => 'test-model',
            'system_prompt' => 'Decide.',
            'messages' => [['type' => 'user', 'content' => 'Ticket body']],
            'structured_response' => ['respond_to_customer' => ['likelihood' => 80]],
            'finish_reason' => 'stop',
            'duration_ms' => 100,
        ]);

        $request->created_at = now()->subDays($daysOld);
        $request->save();

        return $request;
    }

    private function makeExecution(int $daysOld): AiWorkflowExecution
    {
        $execution = AiWorkflowExecution::create(['name' => 'work_ticket']);

        $execution->created_at = now()->subDays($daysOld);
        $execution->save();

        return $execution;
    }

    private function addToDataset(AiWorkflowExecution $execution): void
    {
        $dataset = AiWorkflowEvalDataset::firstOrCreate(['name' => 'golden']);

        AiWorkflowEvalDatasetEntry::create([
            'dataset_id' => $dataset->id,
            'execution_id' => $execution->id,
        ]);
    }
}
