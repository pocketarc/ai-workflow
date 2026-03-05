<?php

declare(strict_types=1);

namespace AiWorkflow\Tests\Fixtures\Data;

use AiWorkflow\Attributes\Description;
use Spatie\LaravelData\Data;

class TypedSentimentData extends Data
{
    public function __construct(
        #[Description('The sentiment type')]
        public readonly SentimentType $type,
        #[Description('Optional explanation')]
        public readonly ?string $reason = null,
    ) {}
}
