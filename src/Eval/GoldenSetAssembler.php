<?php

declare(strict_types=1);

namespace AiWorkflow\Eval;

use AiWorkflow\Models\AiWorkflowAnnotation;
use AiWorkflow\Models\AiWorkflowRequest;
use Illuminate\Support\Collection;

/**
 * Turns human review answers into an eval golden set.
 *
 * Membership follows the label: it is the answer a reviewer settled on, and it
 * is what every model is then scored against. A review that recorded no answer
 * says the request was looked at, not what the right action was, so it has
 * nothing to score against and stays out.
 *
 * Only the most recent annotation per request counts, so re-labelling
 * supersedes the earlier call rather than duplicating it.
 */
class GoldenSetAssembler
{
    /**
     * @param  string|null  $promptId  Restrict to one prompt, or null for all.
     * @param  bool  $correctionsOnly  Keep only the requests whose answer differs
     *                                 from the one the model picked.
     * @param  int|null  $limit  Cap the set size, newest answer first.
     * @return list<AiWorkflowRequest> Each carrying its answer as a transient
     *                                 ground-truth attribute.
     */
    public function assemble(
        ?string $promptId = null,
        bool $correctionsOnly = false,
        ?int $limit = null,
    ): array {
        $query = AiWorkflowAnnotation::query()
            ->latestPerRequest()
            // No label means no answer to score against.
            ->whereNotNull('label')
            ->where('label', '!=', '')
            ->orderByDesc('id');

        if ($promptId !== null) {
            $query->whereRelation('request', 'prompt_id', $promptId);
        }

        // Without the corrections filter the database can do the limiting.
        // With it, which rows survive is only known once they are in memory, so
        // the limit has to wait for them: --corrections --limit=20 should yield
        // twenty corrections, not whatever survives the newest twenty answers.
        if ($limit !== null && ! $correctionsOnly) {
            $query->limit($limit);
        }

        $annotations = $query->get();

        if ($correctionsOnly) {
            $annotations = $this->corrections($annotations);

            if ($limit !== null) {
                $annotations = $annotations->take($limit);
            }
        }

        return $this->withGroundTruth($annotations);
    }

    /**
     * The answers that disagree with what the model picked.
     *
     * Derived by comparing each answer to the winning key of the response it
     * was recorded against, rather than stored alongside it. A reviewer records
     * one thing, the right answer, and whether that amounts to a correction
     * follows from it. Nothing can drift out of step with anything else, and a
     * re-labelled request is reclassified for free.
     *
     * A response with no ranked keys, such as a free-text prompt, cannot be
     * compared and is left out: there is no pick to disagree with.
     *
     * @param  Collection<int, AiWorkflowAnnotation>  $annotations
     * @return Collection<int, AiWorkflowAnnotation>
     */
    private function corrections(Collection $annotations): Collection
    {
        // Selected narrowly: `messages` holds the whole recorded payload,
        // attachments and all, and the pick comes from the response alone.
        $picks = AiWorkflowRequest::query()
            ->select(['id', 'structured_response'])
            ->whereIn('id', $annotations->pluck('request_id')->all())
            ->get()
            ->mapWithKeys(static function (AiWorkflowRequest $request): array {
                $structured = $request->structured_response;

                return [$request->id => is_array($structured)
                    ? StructuredResponsePresenter::topKey($structured)
                    : null];
            });

        return $annotations->filter(static function (AiWorkflowAnnotation $annotation) use ($picks): bool {
            $pick = $picks->get($annotation->request_id);

            return is_string($pick) && $annotation->label !== $pick;
        });
    }

    /**
     * @param  Collection<int, AiWorkflowAnnotation>  $annotations
     * @return list<AiWorkflowRequest>
     */
    private function withGroundTruth(Collection $annotations): array
    {
        $requests = AiWorkflowRequest::query()
            ->whereIn('id', $annotations->pluck('request_id')->all())
            ->get()
            ->keyBy('id');

        $assembled = [];

        foreach ($annotations as $annotation) {
            $request = $requests->get($annotation->request_id);

            if (! $request instanceof AiWorkflowRequest) {
                continue;
            }

            $request->setAttribute(AiWorkflowRequest::GROUND_TRUTH_ATTRIBUTE, $annotation->label);

            $assembled[] = $request;
        }

        return $assembled;
    }
}
