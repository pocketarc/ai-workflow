<?php

declare(strict_types=1);

namespace AiWorkflow\Eval;

use AiWorkflow\Models\AiWorkflowRequest;
use Prism\Prism\Structured\Response as StructuredResponse;
use Prism\Prism\Text\Response;

interface AiWorkflowEvalJudge
{
    /**
     * Judge an AI response against the original recorded request.
     */
    public function judge(AiWorkflowRequest $originalRequest, Response|StructuredResponse $response): AiWorkflowEvalResult;
}
