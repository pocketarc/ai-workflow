<?php

declare(strict_types=1);

namespace AiWorkflow\Eval;

use AiWorkflow\Models\AiWorkflowRequest;

/**
 * Supplies the review UI with the situation around each request — links out to
 * the host app's records, and notes describing what was going on. The library
 * has no idea what a request refers to, so an app that does implements this and
 * names it in `ai-workflow.review.context`.
 */
interface ReviewContextResolver
{
    /**
     * Batched deliberately: a page holds twenty requests, and resolving each
     * one separately would mean a burst of queries per page.
     *
     * @param  list<AiWorkflowRequest>  $requests
     * @return array<int, ReviewContext> Keyed by request id.
     */
    public function resolve(array $requests): array;
}
