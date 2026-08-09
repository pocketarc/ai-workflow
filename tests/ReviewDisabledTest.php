<?php

declare(strict_types=1);

namespace AiWorkflow\Tests;

class ReviewDisabledTest extends DatabaseTestCase
{
    /**
     * The review UI can read and label every recorded prompt and response, so
     * it must not exist unless someone deliberately switched it on. The default
     * config leaves it off.
     */
    public function test_the_review_routes_do_not_exist_by_default(): void
    {
        $this->assertFalse(config('ai-workflow.review.enabled'));

        $this->get('/ai-workflow/review')->assertNotFound();
        $this->get('/ai-workflow/review/1/input')->assertNotFound();
        $this->post('/ai-workflow/review/1/annotate', ['label' => 'respond_to_customer'])->assertNotFound();
    }
}
