<?php

declare(strict_types=1);

namespace AiWorkflow\Exceptions;

use Throwable;

class RetriesExhaustedException extends AiWorkflowException
{
    public function __construct(
        public readonly int $attempts,
        Throwable $previous
    ) {
        parent::__construct(
            $previous->getMessage(),
            (int) $previous->getCode(),
            $previous
        );
    }
}
