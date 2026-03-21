<?php

declare(strict_types=1);

namespace AiWorkflow;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Prism\Prism\Contracts\Message;
use Prism\Prism\Schema\ObjectSchema;

class AiWorkflowCache
{
    /**
     * Generate a deterministic cache key from the request parameters.
     *
     * @param  array<int, Message>  $messages
     */
    public function generateKey(
        string $provider,
        string $model,
        string $systemPrompt,
        array $messages,
        ?ObjectSchema $schema = null,
    ): string {
        $payload = json_encode([
            'provider' => $provider,
            'model' => $model,
            'system_prompt' => $systemPrompt,
            'messages' => MessageSerializer::serialize($messages),
            'schema' => $schema?->toArray(),
        ], JSON_THROW_ON_ERROR);

        return 'ai_workflow:'.hash('xxh128', $payload);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $key): ?array
    {
        /** @var array<string, mixed>|null $value */
        $value = $this->store()->get($key);

        return $value;
    }

    /**
     * @param  array<string, mixed>  $responseData
     */
    public function put(string $key, array $responseData, int $ttlSeconds): void
    {
        $this->store()->put($key, $responseData, $ttlSeconds);
    }

    public function isEnabled(): bool
    {
        /** @var array{enabled: bool, store: string|null} $cacheConfig */
        $cacheConfig = config('ai-workflow.cache');

        return $cacheConfig['enabled'];
    }

    private function store(): Repository
    {
        /** @var array{enabled: bool, store: string|null} $cacheConfig */
        $cacheConfig = config('ai-workflow.cache');

        return Cache::store($cacheConfig['store']);
    }
}
