<?php

declare(strict_types=1);

namespace AiWorkflow\Exceptions;

use RuntimeException;
use Throwable;

class StructuredValidationException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $attempts,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
