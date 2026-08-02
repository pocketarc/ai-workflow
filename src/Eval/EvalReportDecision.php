<?php

declare(strict_types=1);

namespace AiWorkflow\Eval;

/**
 * A single golden-set item and what each model made of it, for qualitative
 * error inspection in the report's drill-down.
 */
class EvalReportDecision
{
    /**
     * @param  array<string, array{predicted: string|null, score: float, correct: bool}>  $byModel
     */
    public function __construct(
        public readonly int $requestId,
        public readonly ?string $groundTruth,
        public readonly string $input,
        public readonly array $byModel,
        public readonly ?ReviewContext $context = null,
    ) {}

    /**
     * Whether the models disagreed with each other — the rows worth reading first.
     */
    public function isContested(): bool
    {
        $predictions = array_map(
            static fn (array $result): ?string => $result['predicted'],
            $this->byModel,
        );

        return count(array_unique($predictions, SORT_REGULAR)) > 1;
    }

    /**
     * Whether every model disagreed with the human label.
     *
     * One model missing an answer is a model problem. All of them missing the
     * same answer is more often the label being wrong or the prompt being
     * unclear, and those are worth re-reading before anything is concluded
     * about the models.
     */
    public function isUnanimouslyAgainstLabel(): bool
    {
        if ($this->groundTruth === null || $this->byModel === []) {
            return false;
        }

        foreach ($this->byModel as $result) {
            if ($result['predicted'] === $this->groundTruth) {
                return false;
            }
        }

        return true;
    }
}
