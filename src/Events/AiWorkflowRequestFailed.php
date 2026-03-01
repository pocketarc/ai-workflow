<?php

declare(strict_types=1);

namespace AiWorkflow\Events;

use AiWorkflow\PromptData;
use Illuminate\Foundation\Events\Dispatchable;
use Throwable;

class AiWorkflowRequestFailed
{
    use Dispatchable;

    public function __construct(
        public readonly PromptData $prompt,
        public readonly string $method,
        public readonly string $model,
        public readonly Throwable $exception,
        public readonly float $durationMs,
        public readonly ?string $executionId,
    ) {}
}
