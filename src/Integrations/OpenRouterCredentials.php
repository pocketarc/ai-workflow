<?php

declare(strict_types=1);

namespace AiWorkflow\Integrations;

use Spatie\LaravelData\Data;

class OpenRouterCredentials extends Data
{
    public function __construct(
        public readonly string $api_key,
    ) {}
}
