<?php

declare(strict_types=1);

namespace AiWorkflow\Tests\Fixtures\Data;

use AiWorkflow\Attributes\ArrayItemType;
use AiWorkflow\Attributes\Description;
use Spatie\LaravelData\Data;

class NestedDefaultsData extends Data
{
    public function __construct(
        #[Description('The name of the resident')]
        public readonly string $name,
        #[Description('The current address')]
        public readonly DefaultedAddressData $address,
        /** @var array<int, DefaultedAddressData> */
        #[ArrayItemType(DefaultedAddressData::class)]
        #[Description('Addresses the resident lived at before')]
        public readonly array $previous = [],
    ) {}
}
