<?php

declare(strict_types=1);

namespace AiWorkflow\Middleware;

use AiWorkflow\Exceptions\GuardrailViolationException;
use Closure;

abstract class InputGuardrail implements AiWorkflowMiddleware
{
    public function handle(AiWorkflowContext $context, Closure $next): AiWorkflowContext
    {
        $this->validate($context);

        return $next($context);
    }

    /**
     * Validate the input before the AI request is sent.
     *
     * @throws GuardrailViolationException
     */
    abstract protected function validate(AiWorkflowContext $context): void;
}
