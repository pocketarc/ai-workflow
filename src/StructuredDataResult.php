<?php

declare(strict_types=1);

namespace AiWorkflow;

use Prism\Prism\Structured\Response as StructuredResponse;
use Prism\Prism\ValueObjects\Usage;
use Spatie\LaravelData\Data;

class StructuredDataResult
{
    public function __construct(
        public readonly Data $data,
        public readonly StructuredResponse $response,
        public readonly Usage $usage,
    ) {}
}
