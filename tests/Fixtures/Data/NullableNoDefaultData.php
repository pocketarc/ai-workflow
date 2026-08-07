<?php

declare(strict_types=1);

namespace AiWorkflow\Tests\Fixtures\Data;

use AiWorkflow\Attributes\Description;
use Spatie\LaravelData\Data;

class NullableNoDefaultData extends Data
{
    public function __construct(
        #[Description('The ID of the chosen category, or null if no category fits')]
        public readonly ?int $category_id,
        #[Description('Confidence in the chosen category, from 0 to 100')]
        public readonly int $confidence,
    ) {}
}
