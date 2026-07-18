<?php

declare(strict_types=1);

namespace AiWorkflow\Tests;

use AiWorkflow\Enums\AnnotationVerdict;
use AiWorkflow\Models\AiWorkflowAnnotation;
use AiWorkflow\Models\AiWorkflowRequest;

class AiWorkflowAnnotationTest extends DatabaseTestCase
{
    public function test_it_persists_a_verdict_label_and_reason(): void
    {
        $request = $this->makeRequest();

        $annotation = AiWorkflowAnnotation::create([
            'request_id' => $request->id,
            'verdict' => AnnotationVerdict::Up,
            'label' => 'respond_to_customer',
            'reason' => 'Correct call.',
            'reviewer' => 'bruno',
        ])->fresh();

        $this->assertNotNull($annotation);
        $this->assertSame(AnnotationVerdict::Up, $annotation->verdict);
        $this->assertSame('respond_to_customer', $annotation->label);
        $this->assertSame('Correct call.', $annotation->reason);
        $this->assertTrue($request->is($annotation->request));
    }

    public function test_latest_per_request_returns_the_most_recent_annotation(): void
    {
        $request = $this->makeRequest();

        AiWorkflowAnnotation::create(['request_id' => $request->id, 'verdict' => AnnotationVerdict::Down]);
        $latest = AiWorkflowAnnotation::create([
            'request_id' => $request->id,
            'verdict' => AnnotationVerdict::Up,
            'label' => 'close_ticket',
        ]);

        $rows = AiWorkflowAnnotation::query()->latestPerRequest()->get();

        $this->assertCount(1, $rows);
        $this->assertTrue($latest->is($rows->first()));
    }

    public function test_verdict_filter_composes_with_latest_per_request(): void
    {
        // Request A: latest verdict is up.
        $requestA = $this->makeRequest();
        AiWorkflowAnnotation::create(['request_id' => $requestA->id, 'verdict' => AnnotationVerdict::Down]);
        AiWorkflowAnnotation::create(['request_id' => $requestA->id, 'verdict' => AnnotationVerdict::Up]);

        // Request B: started up, later corrected to down — so its current verdict is down.
        $requestB = $this->makeRequest();
        AiWorkflowAnnotation::create(['request_id' => $requestB->id, 'verdict' => AnnotationVerdict::Up]);
        AiWorkflowAnnotation::create(['request_id' => $requestB->id, 'verdict' => AnnotationVerdict::Down]);

        $ups = AiWorkflowAnnotation::query()
            ->latestPerRequest()
            ->withVerdict(AnnotationVerdict::Up)
            ->get();

        $this->assertCount(1, $ups);
        $this->assertSame($requestA->id, $ups->first()?->request_id);
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
