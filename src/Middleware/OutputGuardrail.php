<?php

declare(strict_types=1);

namespace AiWorkflow\Middleware;

use AiWorkflow\Exceptions\GuardrailViolationException;
use Closure;

abstract class OutputGuardrail implements AiWorkflowMiddleware
{
    public function handle(AiWorkflowContext $context, Closure $next): AiWorkflowContext
    {
        $context = $next($context);

        $this->validate($context);

        return $context;
    }

    /**
     * Validate the output after the AI response is received.
     *
     * @throws GuardrailViolationException
     */
    abstract protected function validate(AiWorkflowContext $context): void;
}
