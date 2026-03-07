<?php

declare(strict_types=1);

namespace AiWorkflow\Exceptions;

use AiWorkflow\Enums\GuardrailDirection;

class GuardrailViolationException extends AiWorkflowException
{
    public function __construct(
        public readonly string $guardrail,
        public readonly GuardrailDirection $direction,
        string $message = '',
    ) {
        parent::__construct($message !== '' ? $message : "Guardrail '{$guardrail}' violated ({$direction->value})");
    }
}
