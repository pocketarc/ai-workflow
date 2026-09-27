<?php

declare(strict_types=1);

namespace AiWorkflow\Exceptions;

use Prism\Prism\ValueObjects\Usage;
use Throwable;

class StructuredDataRequestException extends AiWorkflowException
{
    public function __construct(
        string $message,
        public readonly int $attempts,
        Usage $usage,
        Throwable $previous,
    ) {
        parent::__construct($message, 0, $previous);

        $this->recordUsage($usage);
    }
}
