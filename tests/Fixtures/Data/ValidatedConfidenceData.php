<?php

declare(strict_types=1);

namespace AiWorkflow\Tests\Fixtures\Data;

use AiWorkflow\Attributes\Description;
use Spatie\LaravelData\Attributes\Validation\Between;
use Spatie\LaravelData\Data;

class ValidatedConfidenceData extends Data
{
    public function __construct(
        #[Description('Confidence from 0 to 100')]
        #[Between(0, 100)]
        public readonly int $confidence,
    ) {}
}
