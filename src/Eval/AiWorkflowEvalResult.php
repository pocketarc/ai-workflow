<?php

declare(strict_types=1);

namespace AiWorkflow\Eval;

use InvalidArgumentException;

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
    ) {
        // Scores are persisted and averaged as they come, so an out-of-range
        // (or NAN) score from a custom judge must fail here, not skew a run.
        if (! is_finite($score) || $score < 0.0 || $score > 1.0) {
            // var_export, because interpolating NAN into a string is itself a
            // coercion error under strict error handling.
            throw new InvalidArgumentException('Eval scores must be finite and between 0.0 and 1.0, got '.var_export($score, true).'.');
        }
    }
}
