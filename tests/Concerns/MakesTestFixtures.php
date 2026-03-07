<?php

declare(strict_types=1);

namespace AiWorkflow\Tests\Concerns;

use AiWorkflow\PromptData;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;

trait MakesTestFixtures
{
    private function makePrompt(
        string $id = 'test',
        string $model = 'openrouter:test-model',
        ?string $fallbackModel = null,
        ?int $cacheTtl = null,
    ): PromptData {
        return new PromptData(
            id: $id,
            model: $model,
            prompt: 'You are a helpful assistant.',
            fallbackModel: $fallbackModel,
            cacheTtl: $cacheTtl,
        );
    }

    private function makeSchema(): ObjectSchema
    {
        return new ObjectSchema(
            name: 'test',
            description: 'A test schema',
            properties: [
                new StringSchema('answer', 'The answer'),
            ],
            requiredFields: ['answer'],
        );
    }
}
