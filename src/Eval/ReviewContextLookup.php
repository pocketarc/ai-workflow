<?php

declare(strict_types=1);

namespace AiWorkflow\Eval;

use AiWorkflow\Models\AiWorkflowRequest;
use LogicException;
use Throwable;

/**
 * Fetches the host app's context for a set of requests.
 *
 * The same records are shown in the review UI and in the eval report, so the
 * config lookup and the failure handling live here rather than once in each.
 */
class ReviewContextLookup
{
    /**
     * @param  list<AiWorkflowRequest>  $requests
     * @return array<int, ReviewContext> Keyed by request id. Empty when no
     *                                   resolver is configured, and empty when
     *                                   the lookup fails.
     */
    public function for(array $requests): array
    {
        if ($requests === []) {
            return [];
        }

        $configured = config('ai-workflow.review.context');

        if (! is_string($configured) || $configured === '' || ! class_exists($configured)) {
            return [];
        }

        try {
            $resolver = app($configured);

            if (! $resolver instanceof ReviewContextResolver) {
                return [];
            }

            return $this->validated($resolver->resolve($requests));
        } catch (Throwable $e) {
            // Context is a convenience: neither labelling a decision nor
            // reporting on one should fail because a resolver threw.
            report($e);

            return [];
        }
    }

    /**
     * ReviewContextResolver is typed to return ReviewContext values keyed by
     * request id, but the resolver itself is host-app code. A wrong-shaped
     * entry would not fail until the view rendered it, outside the catch that
     * keeps context failures harmless. Throwing here keeps that failure inside
     * the catch.
     *
     * @param  array<array-key, mixed>  $resolved
     * @return array<int, ReviewContext>
     */
    private function validated(array $resolved): array
    {
        $contexts = [];

        foreach ($resolved as $id => $context) {
            if (! is_int($id) || ! $context instanceof ReviewContext) {
                throw new LogicException('Review context resolver returned '.get_debug_type($context).' keyed by '.get_debug_type($id).', expected ReviewContext instances keyed by request id.');
            }

            $contexts[$id] = $context;
        }

        return $contexts;
    }
}
