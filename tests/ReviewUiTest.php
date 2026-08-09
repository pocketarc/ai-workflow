<?php

declare(strict_types=1);

namespace AiWorkflow\Tests;

use AiWorkflow\Eval\StructuredResponsePresenter;
use AiWorkflow\Models\AiWorkflowAnnotation;
use AiWorkflow\Models\AiWorkflowRequest;
use AiWorkflow\Tests\Fixtures\ExplodingReviewContext;
use AiWorkflow\Tests\Fixtures\StubReviewContext;
use Override;

class ReviewUiTest extends DatabaseTestCase
{
    #[Override]
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('ai-workflow.review.enabled', true);
        $app['config']->set('ai-workflow.review.reviewer', 'bruno');
    }

    public function test_it_lists_unreviewed_requests_with_a_ranked_table(): void
    {
        $this->makeDecisionRequest();

        $this->get('/ai-workflow/review')
            ->assertOk()
            ->assertSee('Review AI decisions')
            ->assertSee('respond_to_customer')
            ->assertSee('wait_for_customer')
            // The model's own pick is pre-filled as the suggested label.
            ->assertSee('value="respond_to_customer"', escape: false);
    }

    public function test_submitting_the_box_unchanged_records_the_pick_as_the_answer(): void
    {
        $request = $this->makeDecisionRequest();

        // The box starts on the model's own pick, so leaving it alone is how a
        // reviewer says the model was right.
        $this->post("/ai-workflow/review/{$request->id}/annotate", [
            'label' => 'respond_to_customer',
            'reason' => 'Customer asked a direct question.',
        ])->assertRedirect();

        $annotation = AiWorkflowAnnotation::query()->firstOrFail();

        $this->assertSame('respond_to_customer', $annotation->label);
        $this->assertSame('Customer asked a direct question.', $annotation->reason);
        $this->assertSame('bruno', $annotation->reviewer);
    }

    public function test_a_changed_box_records_the_answer_it_was_changed_to(): void
    {
        $request = $this->makeDecisionRequest();

        $this->post("/ai-workflow/review/{$request->id}/annotate", [
            'label' => 'wait_for_development_team',
            'reason' => 'Dev work was still outstanding.',
        ])->assertRedirect();

        $annotation = AiWorkflowAnnotation::query()->firstOrFail();

        // Recorded verbatim. Nothing compares it to the pick on the way in:
        // whether it is a correction is worked out when it is read.
        $this->assertSame('wait_for_development_team', $annotation->label);
        $this->assertSame('Dev work was still outstanding.', $annotation->reason);
    }

    public function test_an_empty_box_records_a_review_with_no_answer(): void
    {
        $request = $this->makeDecisionRequest();

        // Clearing the box is how a reviewer says they looked and could not
        // settle on an answer. It keeps the request out of the queue without
        // putting a guess into the answer key.
        $this->post("/ai-workflow/review/{$request->id}/annotate", [
            'label' => '',
            'reason' => 'Not sure what was right here.',
        ])->assertRedirect();

        $annotation = AiWorkflowAnnotation::query()->firstOrFail();

        $this->assertNull($annotation->label);
        $this->assertSame('Not sure what was right here.', $annotation->reason);
    }

    public function test_it_rejects_a_label_that_is_too_long(): void
    {
        $request = $this->makeDecisionRequest();

        $this->post("/ai-workflow/review/{$request->id}/annotate", ['label' => str_repeat('a', 256)])
            ->assertSessionHasErrors('label');

        $this->assertSame(0, AiWorkflowAnnotation::query()->count());
    }

    public function test_reviewed_requests_drop_off_the_queue(): void
    {
        $request = $this->makeDecisionRequest();

        $this->get('/ai-workflow/review')->assertSee("Request #{$request->id}");

        AiWorkflowAnnotation::create([
            'request_id' => $request->id,
            'label' => 'respond_to_customer',
        ]);

        $this->get('/ai-workflow/review')->assertDontSee("Request #{$request->id}");
        $this->get('/ai-workflow/review?all=1')->assertSee("Request #{$request->id}");
    }

    public function test_it_filters_by_prompt(): void
    {
        $decide = $this->makeDecisionRequest();
        $other = $this->makeDecisionRequest(promptId: 'summarise');

        $this->get('/ai-workflow/review?prompt=decide_next_action')
            ->assertSee("Request #{$decide->id}")
            ->assertDontSee("Request #{$other->id}");
    }

    public function test_a_relabelled_request_shows_its_latest_answer(): void
    {
        $request = $this->makeDecisionRequest();

        AiWorkflowAnnotation::create(['request_id' => $request->id, 'label' => 'close_ticket']);
        AiWorkflowAnnotation::create(['request_id' => $request->id, 'label' => 'wait_for_customer']);

        $this->get('/ai-workflow/review?all=1')
            ->assertOk()
            ->assertSee('last answer:')
            ->assertSee('wait_for_customer');
    }

    public function test_it_renders_context_supplied_by_the_host_app(): void
    {
        config(['ai-workflow.review.context' => StubReviewContext::class]);
        $request = $this->makeDecisionRequest();

        $this->get('/ai-workflow/review')
            ->assertOk()
            ->assertSee('GitHub #4821')
            ->assertSee("https://example.test/issues/{$request->id}")
            ->assertSee('Last comment before this decision — Ada, 1 May 2026')
            ->assertSee('The import failed again overnight.');
    }

    public function test_a_broken_context_resolver_does_not_break_labelling(): void
    {
        // Context is a convenience; a failure in it must not cost the reviewer
        // the page they were working through.
        config(['ai-workflow.review.context' => ExplodingReviewContext::class]);
        $this->makeDecisionRequest();

        $this->get('/ai-workflow/review')
            ->assertOk()
            ->assertSee('Right answer');
    }

    public function test_the_list_does_not_load_bulky_prompt_payloads(): void
    {
        // decide_next_action prompts embed the whole ticket, attachments and
        // all, so a page of them will exhaust memory if `messages` is selected.
        // The list must stay narrow and leave the prompt to the input endpoint.
        $this->makeDecisionRequest();

        $response = $this->get('/ai-workflow/review');
        $response->assertOk();

        $requests = $response->viewData('requests');
        $first = $requests->first();

        $this->assertNotNull($first);
        $this->assertFalse($first->isRelation('messages'));
        $this->assertArrayNotHasKey('messages', $first->getAttributes());
        $this->assertArrayNotHasKey('system_prompt', $first->getAttributes());

        // Narrow, but not so narrow that link resolvers lose the id they need
        // to find the host app's record.
        $this->assertArrayHasKey('execution_id', $first->getAttributes());
    }

    public function test_the_input_endpoint_returns_the_prompt_text(): void
    {
        $request = $this->makeDecisionRequest();

        $this->get("/ai-workflow/review/{$request->id}/input")
            ->assertOk()
            ->assertHeader('content-type', 'text/plain; charset=utf-8')
            ->assertSee('Customer asks when the fix ships.');
    }

    public function test_scored_options_survive_a_sibling_summary_field(): void
    {
        // decide_next_action returns last_comment_summary alongside its nine
        // actions; that must not knock the reader back to raw JSON.
        $ranked = StructuredResponsePresenter::ranked([
            'last_comment_summary' => 'Customer replied asking for an ETA.',
            'respond_to_customer' => ['likelihood' => 82, 'reasoning' => 'Direct question.'],
            'wait_for_customer' => ['likelihood' => 20, 'reasoning' => 'Nothing pending.'],
        ]);

        $this->assertNotNull($ranked);
        $this->assertCount(2, $ranked);
        $this->assertSame('respond_to_customer', $ranked[0]['key']);

        $extras = StructuredResponsePresenter::extras([
            'last_comment_summary' => 'Customer replied asking for an ETA.',
            'respond_to_customer' => ['likelihood' => 82],
        ]);

        $this->assertSame(['last_comment_summary' => 'Customer replied asking for an ETA.'], $extras);
    }

    public function test_the_review_page_renders_a_ranked_table_for_a_real_decision_shape(): void
    {
        AiWorkflowRequest::create([
            'prompt_id' => 'decide_next_action',
            'method' => 'sendStructuredMessages',
            'provider' => 'openrouter',
            'model' => 'claude-opus-4.6',
            'system_prompt' => 'You are Grace.',
            'messages' => [['type' => 'user', 'content' => 'Ticket']],
            'structured_response' => [
                'last_comment_summary' => 'Dev team asked the customer for dropdown options.',
                'close_ticket' => ['likelihood' => 0, 'reasoning' => 'Not resolved.'],
                'wait_for_customer' => ['likelihood' => 88, 'reasoning' => 'Awaiting their answer.'],
            ],
            'finish_reason' => 'stop',
            'duration_ms' => 4200,
        ]);

        $this->get('/ai-workflow/review')
            ->assertOk()
            // The summary shows as context, the options as a ranked table —
            // not a wall of JSON.
            ->assertSee('Dev team asked the customer for dropdown options.')
            ->assertSee('Awaiting their answer.')
            ->assertDontSee('"likelihood":', escape: false);
    }

    public function test_the_presenter_ignores_responses_that_are_not_scored_options(): void
    {
        $this->assertNull(StructuredResponsePresenter::ranked(['summary' => 'a plain string']));
        $this->assertNull(StructuredResponsePresenter::ranked([]));

        $ranked = StructuredResponsePresenter::ranked([
            'a' => ['likelihood' => 10, 'reasoning' => 'low'],
            'b' => ['likelihood' => 90, 'reasoning' => 'high'],
        ]);

        $this->assertNotNull($ranked);
        $this->assertSame('b', $ranked[0]['key']);
        $this->assertSame('b', StructuredResponsePresenter::topKey([
            'a' => ['likelihood' => 10],
            'b' => ['likelihood' => 90],
        ]));
    }

    private function makeDecisionRequest(string $promptId = 'decide_next_action'): AiWorkflowRequest
    {
        return AiWorkflowRequest::create([
            'prompt_id' => $promptId,
            'method' => 'sendStructuredMessages',
            'provider' => 'openrouter',
            'model' => 'claude-opus-4.6',
            'system_prompt' => 'You are Grace.',
            'messages' => [['type' => 'user', 'content' => 'Customer asks when the fix ships.']],
            'structured_response' => [
                'respond_to_customer' => ['likelihood' => 82, 'reasoning' => 'Direct question asked.'],
                'wait_for_customer' => ['likelihood' => 20, 'reasoning' => 'Nothing to wait on.'],
                'close_ticket' => ['likelihood' => 5, 'reasoning' => 'Not resolved.'],
            ],
            'finish_reason' => 'stop',
            'duration_ms' => 4200,
        ]);
    }
}
