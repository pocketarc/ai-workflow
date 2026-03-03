<?php

declare(strict_types=1);

namespace AiWorkflow\Middleware;

use Closure;

interface AiWorkflowMiddleware
{
    /**
     * @param  Closure(AiWorkflowContext): AiWorkflowContext  $next
     */
    public function handle(AiWorkflowContext $context, Closure $next): AiWorkflowContext;
}
