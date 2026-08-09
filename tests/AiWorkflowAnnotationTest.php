<?php

declare(strict_types=1);

namespace AiWorkflow\Tests;

use AiWorkflow\Models\AiWorkflowAnnotation;
use AiWorkflow\Models\AiWorkflowRequest;
use Illuminate\Support\Facades\Schema;

class AiWorkflowAnnotationTest extends DatabaseTestCase
{
    public function test_it_persists_a_label_and_reason(): void
    {
        $request = $this->makeRequest();

        $annotation = AiWorkflowAnnotation::create([
            'request_id' => $request->id,
            'label' => 'respond_to_customer',
            'reason' => 'Correct call.',
            'reviewer' => 'bruno',
        ])->fresh();

        $this->assertNotNull($annotation);
        $this->assertSame('respond_to_customer', $annotation->label);
        $this->assertSame('Correct call.', $annotation->reason);
        $this->assertSame('bruno', $annotation->reviewer);
        $this->assertTrue($request->is($annotation->request));
    }

    public function test_latest_per_request_returns_the_most_recent_annotation(): void
    {
        $request = $this->makeRequest();

        AiWorkflowAnnotation::create(['request_id' => $request->id, 'label' => 'respond_to_customer']);
        $latest = AiWorkflowAnnotation::create([
            'request_id' => $request->id,
            'label' => 'close_ticket',
        ]);

        $rows = AiWorkflowAnnotation::query()->latestPerRequest()->get();

        $this->assertCount(1, $rows);
        $this->assertTrue($latest->is($rows->first()));
    }

    public function test_the_verdict_column_is_gone(): void
    {
        // Dropping it is the point of the migration, and a review records one
        // thing now. A column left behind would invite something to start
        // writing to it again.
        $this->assertFalse(Schema::hasColumn('ai_workflow_annotations', 'verdict'));
        $this->assertTrue(Schema::hasColumn('ai_workflow_annotations', 'label'));
    }

    private function makeRequest(): AiWorkflowRequest
    {
        return AiWorkflowRequest::create([
            'prompt_id' => 'decide_next_action',
            'method' => 'sendStructuredMessages',
            'provider' => 'openrouter',
            'model' => 'test-model',
            'system_prompt' => 'Decide.',
            'messages' => [['type' => 'user', 'content' => 'Ticket']],
            'structured_response' => ['respond_to_customer' => ['likelihood' => 80, 'reasoning' => 'x']],
            'finish_reason' => 'stop',
            'duration_ms' => 100,
        ]);
    }
}
