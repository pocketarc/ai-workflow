<?php

declare(strict_types=1);

namespace AiWorkflow\Tests\Fixtures;

use AiWorkflow\Eval\ReviewContext;
use AiWorkflow\Eval\ReviewContextResolver;
use AiWorkflow\Models\AiWorkflowRequest;
use Override;
use RuntimeException;

class ExplodingReviewContext implements ReviewContextResolver
{
    /**
     * @param  list<AiWorkflowRequest>  $requests
     * @return array<int, ReviewContext>
     */
    #[Override]
    public function resolve(array $requests): array
    {
        throw new RuntimeException('context lookup failed');
    }
}
