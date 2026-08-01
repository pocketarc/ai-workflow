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
}
