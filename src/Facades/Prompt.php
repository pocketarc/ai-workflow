<?php

declare(strict_types=1);

namespace AiWorkflow\Facades;

use AiWorkflow\PromptData;
use AiWorkflow\PromptService;
use Illuminate\Support\Facades\Facade;
use Override;

/**
 * @method static PromptData load(string $id, array<string, mixed> $variables = [])
 *
 * @see PromptService
 */
class Prompt extends Facade
{
    #[Override]
    protected static function getFacadeAccessor(): string
    {
        return PromptService::class;
    }
}
