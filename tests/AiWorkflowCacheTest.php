<?php

declare(strict_types=1);

namespace AiWorkflow\Tests;

use AiWorkflow\AiService;
use AiWorkflow\AiWorkflowCache;
use AiWorkflow\PromptData;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Testing\StructuredResponseFake;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\ValueObjects\Messages\UserMessage;

class AiWorkflowCacheTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('ai-workflow.cache.enabled', true);
        config()->set('ai-workflow.cache.store', 'array');
    }

    private function makePrompt(?int $cacheTtl = 3600): PromptData
    {
        return new PromptData(
            id: 'test',
            model: 'openrouter:test-model',
            prompt: 'You are a helpful assistant.',
            cacheTtl: $cacheTtl,
        );
    }

    public function test_deterministic_key_generation(): void
    {
        $cache = app(AiWorkflowCache::class);

        $key1 = $cache->generateKey('openrouter', 'test', 'system', [new UserMessage('Hello')]);
        $key2 = $cache->generateKey('openrouter', 'test', 'system', [new UserMessage('Hello')]);
        $key3 = $cache->generateKey('openrouter', 'test', 'system', [new UserMessage('Different')]);

        $this->assertSame($key1, $key2);
        $this->assertNotSame($key1, $key3);
        $this->assertStringStartsWith('ai_workflow:', $key1);
    }

    public function test_schema_affects_cache_key(): void
    {
        $cache = app(AiWorkflowCache::class);
        $schema = new ObjectSchema('test', 'desc', [new StringSchema('a', 'b')], ['a']);

        $withoutSchema = $cache->generateKey('openrouter', 'test', 'system', [new UserMessage('Hello')]);
        $withSchema = $cache->generateKey('openrouter', 'test', 'system', [new UserMessage('Hello')], $schema);

        $this->assertNotSame($withoutSchema, $withSchema);
    }

    public function test_cache_hit_returns_cached_text_response(): void
    {
        Prism::fake([
            TextResponseFake::make()->withText('Original response')->withFinishReason(FinishReason::Stop),
        ]);

        $service = app(AiService::class);
        $prompt = $this->makePrompt();

        // First call — cache miss, hits Prism.
        $response1 = $service->sendMessages(collect([new UserMessage('Hello')]), $prompt);
        $this->assertSame('Original response', $response1->text);

        // Second call — cache hit, does not hit Prism (no more fakes needed).
        $response2 = $service->sendMessages(collect([new UserMessage('Hello')]), $prompt);
        $this->assertSame('Original response', $response2->text);
    }

    public function test_cache_hit_returns_cached_structured_response(): void
    {
        $schema = new ObjectSchema('test', 'desc', [new StringSchema('answer', 'The answer')], ['answer']);

        Prism::fake([
            StructuredResponseFake::make()
                ->withStructured(['answer' => 'cached'])
                ->withFinishReason(FinishReason::Stop),
        ]);

        $service = app(AiService::class);
        $prompt = $this->makePrompt();

        $response1 = $service->sendStructuredMessages(collect([new UserMessage('Hello')]), $prompt, $schema);
        $this->assertSame(['answer' => 'cached'], $response1->structured);

        // Cache hit.
        $response2 = $service->sendStructuredMessages(collect([new UserMessage('Hello')]), $prompt, $schema);
        $this->assertSame(['answer' => 'cached'], $response2->structured);
    }

    public function test_cache_miss_when_different_messages(): void
    {
        Prism::fake([
            TextResponseFake::make()->withText('First')->withFinishReason(FinishReason::Stop),
            TextResponseFake::make()->withText('Second')->withFinishReason(FinishReason::Stop),
        ]);

        $service = app(AiService::class);
        $prompt = $this->makePrompt();

        $response1 = $service->sendMessages(collect([new UserMessage('Hello')]), $prompt);
        $response2 = $service->sendMessages(collect([new UserMessage('Goodbye')]), $prompt);

        $this->assertSame('First', $response1->text);
        $this->assertSame('Second', $response2->text);
    }

    public function test_cache_skipped_when_no_ttl(): void
    {
        Prism::fake([
            TextResponseFake::make()->withText('First')->withFinishReason(FinishReason::Stop),
            TextResponseFake::make()->withText('Second')->withFinishReason(FinishReason::Stop),
        ]);

        $service = app(AiService::class);
        $prompt = $this->makePrompt(cacheTtl: null);

        $response1 = $service->sendMessages(collect([new UserMessage('Hello')]), $prompt);
        $response2 = $service->sendMessages(collect([new UserMessage('Hello')]), $prompt);

        $this->assertSame('First', $response1->text);
        $this->assertSame('Second', $response2->text);
    }

    public function test_cache_skipped_when_globally_disabled(): void
    {
        config()->set('ai-workflow.cache.enabled', false);

        Prism::fake([
            TextResponseFake::make()->withText('First')->withFinishReason(FinishReason::Stop),
            TextResponseFake::make()->withText('Second')->withFinishReason(FinishReason::Stop),
        ]);

        $service = app(AiService::class);
        $prompt = $this->makePrompt();

        $response1 = $service->sendMessages(collect([new UserMessage('Hello')]), $prompt);
        $response2 = $service->sendMessages(collect([new UserMessage('Hello')]), $prompt);

        $this->assertSame('First', $response1->text);
        $this->assertSame('Second', $response2->text);
    }
}
