<?php

declare(strict_types=1);

namespace AiWorkflow\Tests\Fixtures\Data;

use AiWorkflow\Attributes\Description;
use Spatie\LaravelData\Data;

class DefaultedData extends Data
{
    public function __construct(
        #[Description('The sentiment of the text')]
        public readonly string $sentiment,
        #[Description('The reason for the sentiment')]
        public readonly ?string $reason = null,
        #[Description('The language of the text, as an ISO 639-1 code')]
        public readonly string $language = 'en',
        #[Description('The tone of the text')]
        public readonly ?string $tone = 'neutral',
    ) {}
}
