<?php

declare(strict_types=1);

namespace AiWorkflow\Tests\Fixtures;

use AiWorkflow\Eval\ReviewContext;
use AiWorkflow\Eval\ReviewContextResolver;
use AiWorkflow\Models\AiWorkflowRequest;
use Override;

class StubReviewContext implements ReviewContextResolver
{
    /**
     * @param  list<AiWorkflowRequest>  $requests
     * @return array<int, ReviewContext>
     */
    #[Override]
    public function resolve(array $requests): array
    {
        $context = [];

        foreach ($requests as $request) {
            $context[$request->id] = new ReviewContext(
                links: [['label' => 'GitHub #4821', 'url' => "https://example.test/issues/{$request->id}"]],
                notes: ['Last comment before this decision — Ada, 1 May 2026' => 'The import failed again overnight.'],
            );
        }

        return $context;
    }
}
