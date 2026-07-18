<?php

declare(strict_types=1);

namespace AiWorkflow\Eval;

class AiWorkflowEvalResult
{
    /**
     * @param  float  $score  Normalised 0.0–1.0 quality score.
     * @param  array<string, mixed>  $details  Judge-specific diagnostic data.
     * @param  string|null  $predicted  The label the judged response chose, for
     *                                  classification prompts. Persisted on the
     *                                  score so reports can build a confusion
     *                                  matrix without knowing the judge.
     */
    public function __construct(
        public readonly float $score,
        public readonly array $details = [],
        public readonly ?string $predicted = null,
    ) {}
}
