<?php

declare(strict_types=1);

namespace AiWorkflow\Exceptions;

use RuntimeException;

class GuardrailViolationException extends RuntimeException
{
    public function __construct(
        public readonly string $guardrail,
        public readonly string $direction,
        string $message = '',
    ) {
        parent::__construct($message !== '' ? $message : "Guardrail '{$guardrail}' violated ({$direction})");
    }
}
