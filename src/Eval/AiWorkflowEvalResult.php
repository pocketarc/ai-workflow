<?php

declare(strict_types=1);

namespace AiWorkflow\Eval;

class AiWorkflowEvalResult
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public readonly float $score,
        public readonly array $details = [],
    ) {}
}
