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
     * Whether the host app named a resolver, so callers can skip the work of
     * gathering requests that nothing will be done with.
     */
    public function isConfigured(): bool
    {
        return $this->configuredResolver() !== null;
    }

    /**
     * @return class-string|null
     */
    private function configuredResolver(): ?string
    {
        $configured = config('ai-workflow.review.context');

        return is_string($configured) && $configured !== '' && class_exists($configured)
            ? $configured
            : null;
    }

    /**
     * @param  list<AiWorkflowRequest>  $requests
     * @return array<int, ReviewContext> Keyed by request id. Empty when no
     *                                   resolver is configured, and empty when
     *                                   the lookup fails.
     */
    public function for(array $requests): array
    {
        $configured = $this->configuredResolver();

        if ($requests === [] || $configured === null) {
            return [];
        }

        try {
            $resolver = app($configured);

            if (! $resolver instanceof ReviewContextResolver) {
                // Thrown rather than returned, so a misconfigured resolver is
                // reported like a broken one instead of silently doing nothing.
                throw new LogicException($configured.' is configured as the review context resolver but does not implement '.ReviewContextResolver::class.'.');
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
