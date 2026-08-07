<?php

declare(strict_types=1);

namespace AiWorkflow\Tests\Fixtures\Data;

use AiWorkflow\Attributes\Description;
use Spatie\LaravelData\Data;

class DefaultedAddressData extends Data
{
    public function __construct(
        #[Description('The street of the address')]
        public readonly string $street,
        #[Description('The country of the address')]
        public readonly string $country = 'UK',
    ) {}
}
