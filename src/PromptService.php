<?php

declare(strict_types=1);

namespace AiWorkflow;

use RuntimeException;
use Spatie\YamlFrontMatter\YamlFrontMatter;

class PromptService
{
    public function load(string $id): PromptData
    {
        $path = $this->resolvePromptPath($id);

        if (! file_exists($path)) {
            throw new RuntimeException("Prompt file not found: {$id}");
        }

        $document = YamlFrontMatter::parseFile($path);

        $model = $document->matter('model');
        $fallbackModel = $document->matter('fallback_model');

        if (! is_string($model)) {
            throw new RuntimeException(
                "Prompt file {$id} missing required 'model' in front matter"
            );
        }

        return new PromptData(
            id: $id,
            model: $model,
            prompt: trim($document->body()),
            fallbackModel: is_string($fallbackModel) ? $fallbackModel : null,
        );
    }

    private function resolvePromptPath(string $id): string
    {
        /** @var string $basePath */
        $basePath = config('ai-workflow.prompts_path');

        return "{$basePath}/{$id}.md";
    }
}
