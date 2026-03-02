<?php

declare(strict_types=1);

namespace AiWorkflow\Tests;

use AiWorkflow\PromptData;
use AiWorkflow\PromptService;
use RuntimeException;

class PromptServiceTest extends TestCase
{
    private PromptService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(PromptService::class);
    }

    public function test_load_returns_prompt_data(): void
    {
        $prompt = $this->service->load('test_prompt');

        $this->assertInstanceOf(PromptData::class, $prompt);
        $this->assertSame('test_prompt', $prompt->id);
        $this->assertSame('openrouter:test/model', $prompt->model);
        $this->assertSame('You are a helpful test assistant.', $prompt->prompt);
        $this->assertNull($prompt->fallbackModel);
    }

    public function test_load_parses_fallback_model(): void
    {
        $prompt = $this->service->load('fallback_prompt');

        $this->assertSame('openrouter:test/primary-model', $prompt->model);
        $this->assertSame('openrouter:test/fallback-model', $prompt->fallbackModel);
    }

    public function test_load_parses_provider_in_model(): void
    {
        $prompt = $this->service->load('provider_prompt');

        $this->assertSame('anthropic:claude-opus-4.5', $prompt->model);
        [$provider, $model] = PromptData::parseModelIdentifier($prompt->model);
        $this->assertSame('anthropic', $provider);
        $this->assertSame('claude-opus-4.5', $model);
    }

    public function test_load_throws_for_missing_file(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Prompt file not found: nonexistent');

        $this->service->load('nonexistent');
    }

    public function test_load_throws_for_invalid_front_matter(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("missing required 'model'");

        $this->service->load('invalid_prompt');
    }

    public function test_load_with_variables_renders_template(): void
    {
        $prompt = $this->service->load('template_prompt', [
            'customer_name' => 'Jane',
            'product' => 'Pro',
            'is_vip' => true,
        ]);

        $this->assertStringContainsString('You are helping Jane with their Pro subscription.', $prompt->prompt);
        $this->assertStringContainsString('This is a VIP customer.', $prompt->prompt);
    }

    public function test_load_with_variables_omits_falsy_sections(): void
    {
        $prompt = $this->service->load('template_prompt', [
            'customer_name' => 'Bob',
            'product' => 'Basic',
            'is_vip' => false,
        ]);

        $this->assertStringContainsString('You are helping Bob with their Basic subscription.', $prompt->prompt);
        $this->assertStringNotContainsString('VIP', $prompt->prompt);
    }

    public function test_load_without_variables_passes_through(): void
    {
        $prompt = $this->service->load('template_prompt');

        $this->assertStringContainsString('{{ customer_name }}', $prompt->prompt);
    }

    public function test_load_preserves_raw_template(): void
    {
        $prompt = $this->service->load('template_prompt', [
            'customer_name' => 'Jane',
            'product' => 'Pro',
        ]);

        $this->assertNotNull($prompt->rawTemplate);
        $this->assertStringContainsString('{{ customer_name }}', $prompt->rawTemplate);
        $this->assertStringContainsString('Jane', $prompt->prompt);
    }

    public function test_raw_template_set_even_without_variables(): void
    {
        $prompt = $this->service->load('test_prompt');

        $this->assertNotNull($prompt->rawTemplate);
        $this->assertSame($prompt->prompt, $prompt->rawTemplate);
    }
}
