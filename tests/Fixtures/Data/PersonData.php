<?php

declare(strict_types=1);

namespace AiWorkflow\Tests\Fixtures\Data;

use AiWorkflow\Attributes\Description;
use Spatie\LaravelData\Data;

class PersonData extends Data
{
    public function __construct(
        #[Description('Full name')]
        public readonly string $name,
        #[Description('Age in years')]
        public readonly int $age,
        #[Description('Home address')]
        public readonly AddressData $address,
    ) {}
}
