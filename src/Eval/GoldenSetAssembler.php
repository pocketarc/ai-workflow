<?php

declare(strict_types=1);

namespace AiWorkflow\Eval;

use AiWorkflow\Enums\AnnotationVerdict;
use AiWorkflow\Models\AiWorkflowAnnotation;
use AiWorkflow\Models\AiWorkflowRequest;

/**
 * Turns human review verdicts into an eval golden set.
 *
 * Membership follows the *label*, not the verdict. An approval says the model's
 * pick was right; a rejection carrying a correction says what should have
 * happened instead. Both state a correct answer, so both belong in the set — and
 * the corrections are the more interesting half, since they are the cases the
 * model is known to get wrong.
 *
 * Only the most recent annotation per request counts, so re-labelling supersedes
 * the earlier call rather than duplicating it.
 */
class GoldenSetAssembler
{
    /**
     * @param  string|null  $promptId  Restrict to one prompt, or null for all.
     * @param  AnnotationVerdict|null  $verdict  Restrict to one verdict, or null
     *                                           for every labelled request.
     * @param  int|null  $limit  Cap the set size, newest label first.
     * @return list<AiWorkflowRequest> Each carrying its answer as a transient
     *                                 ground-truth attribute.
     */
    public function assemble(
        ?string $promptId = null,
        ?AnnotationVerdict $verdict = null,
        ?int $limit = null,
    ): array {
        $query = AiWorkflowAnnotation::query()
            ->latestPerRequest()
            // No label means no answer to score against, whatever the verdict.
            ->whereNotNull('label')
            ->where('label', '!=', '')
            ->with('request')
            ->orderByDesc('id');

        if ($verdict !== null) {
            $query->withVerdict($verdict);
        }

        if ($promptId !== null) {
            $query->whereRelation('request', 'prompt_id', $promptId);
        }

        if ($limit !== null) {
            $query->limit($limit);
        }

        $requests = [];

        foreach ($query->get() as $annotation) {
            $request = $annotation->request;
            $request->setAttribute(AiWorkflowRequest::GROUND_TRUTH_ATTRIBUTE, $annotation->label);

            $requests[] = $request;
        }

        return $requests;
    }
}
