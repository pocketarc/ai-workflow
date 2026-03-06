<?php

declare(strict_types=1);

namespace AiWorkflow\Tests;

use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\StructuredResponseFake;
use Prism\Prism\Testing\TextResponseFake;

class PromptTestCommandTest extends TestCase
{
    public function test_runs_single_prompt_test(): void
    {
        Prism::fake([
            TextResponseFake::make()
                ->withText('Hello! How can I help you?')
                ->withFinishReason(FinishReason::Stop),
        ]);

        $this->artisan('ai-workflow:prompt-test', ['prompt' => 'test_prompt'])
            ->expectsOutputToContain('PASS: Basic text response')
            ->expectsOutputToContain('Results: 1/1 passed')
            ->assertExitCode(0);
    }

    public function test_contains_assertion_fails_when_missing(): void
    {
        Prism::fake([
            TextResponseFake::make()
                ->withText('Goodbye cruel world')
                ->withFinishReason(FinishReason::Stop),
        ]);

        $this->artisan('ai-workflow:prompt-test', ['prompt' => 'test_prompt'])
            ->expectsOutputToContain('FAIL: Basic text response')
            ->expectsOutputToContain('Results: 0/1 passed')
            ->assertExitCode(1);
    }

    public function test_runs_all_prompt_tests(): void
    {
        Prism::fake([
            // template_prompt (alphabetically first)
            TextResponseFake::make()
                ->withText('Hi Jane Doe, welcome to your Pro Plan support.')
                ->withFinishReason(FinishReason::Stop),
            // test_prompt
            TextResponseFake::make()
                ->withText('Hello there!')
                ->withFinishReason(FinishReason::Stop),
        ]);

        $this->artisan('ai-workflow:prompt-test')
            ->expectsOutputToContain('Results: 2/2 passed')
            ->assertExitCode(0);
    }

    public function test_warns_on_missing_test_file(): void
    {
        $this->artisan('ai-workflow:prompt-test', ['prompt' => 'nonexistent'])
            ->expectsOutputToContain('No test file found')
            ->assertExitCode(0);
    }

    public function test_template_variables_are_injected(): void
    {
        Prism::fake([
            TextResponseFake::make()
                ->withText('Hello Jane Doe, I see you are on the Pro Plan. As a VIP, let me help you right away.')
                ->withFinishReason(FinishReason::Stop),
        ]);

        $this->artisan('ai-workflow:prompt-test', ['prompt' => 'template_prompt'])
            ->expectsOutputToContain('PASS: VIP customer greeting')
            ->assertExitCode(0);
    }

    public function test_structured_assertion(): void
    {
        Prism::fake([
            StructuredResponseFake::make()
                ->withStructured(['intent' => 'billing', 'confidence' => '0.9'])
                ->withFinishReason(FinishReason::Stop),
        ]);

        $testFile = $this->createTempTestFile('structured_test', [
            'cases' => [
                [
                    'name' => 'Intent classification',
                    'messages' => [
                        ['role' => 'user', 'content' => 'How do I update my credit card?'],
                    ],
                    'assert' => [
                        'structured' => ['intent' => 'billing'],
                    ],
                ],
            ],
        ]);

        $this->artisan('ai-workflow:prompt-test', ['prompt' => 'structured_test'])
            ->expectsOutputToContain('PASS: Intent classification')
            ->assertExitCode(0);

        unlink($testFile);
    }

    public function test_structured_assertion_failure(): void
    {
        Prism::fake([
            StructuredResponseFake::make()
                ->withStructured(['intent' => 'support'])
                ->withFinishReason(FinishReason::Stop),
        ]);

        $testFile = $this->createTempTestFile('structured_fail', [
            'cases' => [
                [
                    'name' => 'Wrong classification',
                    'messages' => [
                        ['role' => 'user', 'content' => 'Help'],
                    ],
                    'assert' => [
                        'structured' => ['intent' => 'billing'],
                    ],
                ],
            ],
        ]);

        $this->artisan('ai-workflow:prompt-test', ['prompt' => 'structured_fail'])
            ->expectsOutputToContain('FAIL: Wrong classification')
            ->assertExitCode(1);

        unlink($testFile);
    }

    public function test_model_override(): void
    {
        Prism::fake([
            TextResponseFake::make()
                ->withText('Hello from override model!')
                ->withFinishReason(FinishReason::Stop),
        ]);

        $this->artisan('ai-workflow:prompt-test', [
            'prompt' => 'test_prompt',
            '--model' => 'anthropic:claude-4',
        ])
            ->expectsOutputToContain('PASS: Basic text response')
            ->assertExitCode(0);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createTempTestFile(string $name, array $data): string
    {
        /** @var string $basePath */
        $basePath = config('ai-workflow.prompts_path');

        // Create a matching prompt file
        $promptPath = "{$basePath}/{$name}.md";
        file_put_contents($promptPath, "---\nmodel: openrouter:test/model\n---\n\nTest prompt.");

        // Create test file
        $testPath = "{$basePath}/tests/{$name}.yaml";
        file_put_contents($testPath, \Symfony\Component\Yaml\Yaml::dump($data, 4));

        // Clean up prompt file on destruction (test file cleaned by caller)
        register_shutdown_function(static function () use ($promptPath): void {
            if (file_exists($promptPath)) {
                unlink($promptPath);
            }
        });

        return $testPath;
    }
}
