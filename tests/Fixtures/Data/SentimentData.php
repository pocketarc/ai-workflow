<?php

declare(strict_types=1);

namespace AiWorkflow\Tests\Fixtures\Data;

use AiWorkflow\Attributes\Description;
use Spatie\LaravelData\Data;

class SentimentData extends Data
{
    public function __construct(
        #[Description('The detected sentiment: positive, negative, or neutral')]
        public readonly string $sentiment,
        #[Description('Confidence score from 0.0 to 1.0')]
        public readonly float $confidence,
    ) {}
}
