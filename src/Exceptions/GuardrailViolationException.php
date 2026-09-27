<?php

declare(strict_types=1);

namespace AiWorkflow\Exceptions;

use AiWorkflow\Enums\GuardrailDirection;
use Prism\Prism\ValueObjects\Usage;

class GuardrailViolationException extends AiWorkflowException
{
    private ?Usage $usage = null;

    public function __construct(
        public readonly string $guardrail,
        public readonly GuardrailDirection $direction,
        string $message = '',
    ) {
        parent::__construct($message !== '' ? $message : "Guardrail '{$guardrail}' violated ({$direction->value})");
    }

    /**
     * Token usage summed across the sendStructuredData() attempts before the one the
     * guardrail rejected. Null if the exception came from any other method.
     */
    public function usage(): ?Usage
    {
        return $this->usage;
    }

    /**
     * @internal
     */
    public function recordUsage(Usage $usage): void
    {
        $this->usage = $usage;
    }
}
