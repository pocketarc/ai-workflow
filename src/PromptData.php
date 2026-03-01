<?php

declare(strict_types=1);

namespace AiWorkflow;

class PromptData
{
    public function __construct(
        public readonly string $id,
        public readonly string $model,
        public readonly string $prompt,
        public readonly ?string $fallbackModel = null,
    ) {}

    /**
     * Parse a unified model identifier into [provider, model].
     *
     * @return array{string, string}
     */
    public static function parseModelIdentifier(string $identifier): array
    {
        $colonPos = strpos($identifier, ':');

        if ($colonPos === false) {
            throw new \RuntimeException("Invalid model identifier '{$identifier}': must be in 'provider:model' format (e.g. 'openrouter:anthropic/claude-4')");
        }

        return [substr($identifier, 0, $colonPos), substr($identifier, $colonPos + 1)];
    }
}
