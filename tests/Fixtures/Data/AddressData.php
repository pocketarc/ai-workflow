<?php

declare(strict_types=1);

namespace AiWorkflow\Tests\Fixtures\Data;

use Spatie\LaravelData\Data;

class AddressData extends Data
{
    public function __construct(
        public readonly string $street,
        public readonly string $city,
    ) {}
}
