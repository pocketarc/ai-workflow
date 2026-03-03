<?php

declare(strict_types=1);

namespace AiWorkflow;

use Mustache\Engine;
use RuntimeException;
use Spatie\YamlFrontMatter\YamlFrontMatter;

class PromptService
{
    private Engine $mustache;

    public function __construct()
    {
        $this->mustache = new Engine([
            'escape' => fn (string $value): string => $value,
        ]);
    }

    /**
     * @param  array<string, mixed>  $variables
     */
    public function load(string $id, array $variables = []): PromptData
    {
        $path = $this->resolvePromptPath($id);

        if (! file_exists($path)) {
            throw new RuntimeException("Prompt file not found: {$id}");
        }

        $document = YamlFrontMatter::parseFile($path);

        $model = $document->matter('model');
        $fallbackModel = $document->matter('fallback_model');
        $tags = $document->matter('tags');
        $cacheTtl = $document->matter('cache_ttl');

        if (! is_string($model)) {
            throw new RuntimeException(
                "Prompt file {$id} missing required 'model' in front matter"
            );
        }

        $rawTemplate = trim($document->body());
        $prompt = $variables !== []
            ? $this->mustache->render($rawTemplate, $variables)
            : $rawTemplate;

        /** @var list<string> $parsedTags */
        $parsedTags = is_array($tags) ? array_values(array_filter($tags, 'is_string')) : [];

        return new PromptData(
            id: $id,
            model: $model,
            prompt: $prompt,
            fallbackModel: is_string($fallbackModel) ? $fallbackModel : null,
            rawTemplate: $rawTemplate,
            tags: $parsedTags,
            cacheTtl: is_int($cacheTtl) ? $cacheTtl : null,
        );
    }

    private function resolvePromptPath(string $id): string
    {
        /** @var string $basePath */
        $basePath = config('ai-workflow.prompts_path');

        return "{$basePath}/{$id}.md";
    }
}
