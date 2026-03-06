<?php

declare(strict_types=1);

namespace AiWorkflow\Console;

use AiWorkflow\AiService;
use AiWorkflow\PromptData;
use AiWorkflow\PromptService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Prism\Prism\Contracts\Message;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Structured\Response as StructuredResponse;
use Prism\Prism\Text\Response;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Symfony\Component\Yaml\Yaml;

class PromptTestCommand extends Command
{
    /** @var string */
    protected $signature = 'ai-workflow:prompt-test
        {prompt? : Prompt ID to test (omit to run all)}
        {--model= : Override the prompt model (provider:model format)}';

    /** @var string */
    protected $description = 'Run prompt tests defined in YAML files against AI models.';

    private int $passed = 0;

    private int $failed = 0;

    public function handle(PromptService $promptService, AiService $aiService): int
    {
        $promptId = $this->argument('prompt');
        $testFiles = is_string($promptId) && $promptId !== ''
            ? $this->findTestFile($promptId)
            : $this->findAllTestFiles();

        if ($testFiles === []) {
            $this->warn('No test files found.');

            return self::SUCCESS;
        }

        foreach ($testFiles as $testFile) {
            $this->runTestFile($testFile, $promptService, $aiService);
        }

        $this->newLine();
        $total = $this->passed + $this->failed;
        $this->info("Results: {$this->passed}/{$total} passed");

        return $this->failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function findTestFile(string $promptId): array
    {
        $path = $this->testsPath()."/{$promptId}.yaml";

        if (! file_exists($path)) {
            $this->warn("No test file found for prompt '{$promptId}' at {$path}");

            return [];
        }

        return [$path];
    }

    /**
     * @return list<string>
     */
    private function findAllTestFiles(): array
    {
        $dir = $this->testsPath();

        if (! is_dir($dir)) {
            return [];
        }

        $files = glob("{$dir}/*.yaml");

        return is_array($files) ? $files : [];
    }

    private function testsPath(): string
    {
        /** @var string $basePath */
        $basePath = config('ai-workflow.prompts_path');

        return "{$basePath}/tests";
    }

    private function runTestFile(string $path, PromptService $promptService, AiService $aiService): void
    {
        $promptId = pathinfo($path, PATHINFO_FILENAME);
        $this->info("Testing prompt: {$promptId}");

        $content = file_get_contents($path);
        if ($content === false) {
            $this->error("  Could not read {$path}");
            $this->failed++;

            return;
        }

        /** @var array<string, mixed> $testData */
        $testData = Yaml::parse($content);

        /** @var array<string, mixed> $variables */
        $variables = is_array($testData['variables'] ?? null) ? $testData['variables'] : [];

        /** @var list<array<string, mixed>> $cases */
        $cases = is_array($testData['cases'] ?? null) ? $testData['cases'] : [];

        if ($cases === []) {
            $this->warn("  No test cases found in {$path}");

            return;
        }

        $prompt = $promptService->load($promptId, $variables);

        $modelOverride = $this->option('model');
        if (is_string($modelOverride) && $modelOverride !== '') {
            $prompt = new PromptData(
                id: $prompt->id,
                model: $modelOverride,
                prompt: $prompt->prompt,
                fallbackModel: $prompt->fallbackModel,
                rawTemplate: $prompt->rawTemplate,
                tags: $prompt->tags,
                cacheTtl: $prompt->cacheTtl,
            );
        }

        foreach ($cases as $case) {
            $this->runTestCase($case, $prompt, $aiService);
        }
    }

    /**
     * @param  array<string, mixed>  $case
     */
    private function runTestCase(array $case, PromptData $prompt, AiService $aiService): void
    {
        $name = is_string($case['name'] ?? null) ? $case['name'] : 'unnamed';

        /** @var list<array<string, mixed>> $rawMessages */
        $rawMessages = is_array($case['messages'] ?? null) ? $case['messages'] : [];

        /** @var array<string, mixed> $assertions */
        $assertions = is_array($case['assert'] ?? null) ? $case['assert'] : [];

        $messages = $this->buildMessages($rawMessages);

        try {
            if (isset($assertions['structured'])) {
                $schema = $this->buildSchemaFromAssertions($assertions);
                $response = $aiService->sendStructuredMessages($messages, $prompt, $schema);
                $this->runAssertions($name, $assertions, $response);
            } else {
                $response = $aiService->sendMessages($messages, $prompt);
                $this->runAssertions($name, $assertions, $response);
            }
        } catch (\Throwable $e) {
            $this->error("  FAIL: {$name} — {$e->getMessage()}");
            $this->failed++;
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rawMessages
     * @return Collection<int, Message>
     */
    private function buildMessages(array $rawMessages): Collection
    {
        /** @var list<Message> $messages */
        $messages = [];

        foreach ($rawMessages as $msg) {
            $content = is_string($msg['content'] ?? null) ? $msg['content'] : '';
            $messages[] = new UserMessage($content);
        }

        return new Collection($messages);
    }

    /**
     * @param  array<string, mixed>  $assertions
     */
    private function runAssertions(string $name, array $assertions, Response|StructuredResponse $response): void
    {
        $failures = [];

        if (isset($assertions['contains'])) {
            $text = $response instanceof StructuredResponse
                ? json_encode($response->structured, JSON_THROW_ON_ERROR)
                : $response->text;

            /** @var list<mixed> $rawNeedles */
            $rawNeedles = is_array($assertions['contains']) ? $assertions['contains'] : [$assertions['contains']];

            foreach ($rawNeedles as $needle) {
                if (! is_string($needle)) {
                    continue;
                }
                if (! str_contains(mb_strtolower($text), mb_strtolower($needle))) {
                    $failures[] = "Response does not contain '{$needle}'";
                }
            }
        }

        if (isset($assertions['structured']) && $response instanceof StructuredResponse) {
            /** @var array<string, mixed> $expected */
            $expected = is_array($assertions['structured']) ? $assertions['structured'] : [];

            foreach ($expected as $key => $expectedValue) {
                $actual = $response->structured[$key] ?? null;
                if (json_encode($actual) !== json_encode($expectedValue)) {
                    $failures[] = "structured.{$key}: expected ".json_encode($expectedValue).', got '.json_encode($actual);
                }
            }
        }

        if ($failures !== []) {
            foreach ($failures as $failure) {
                $this->error("  FAIL: {$name} — {$failure}");
            }
            $this->failed++;
        } else {
            $this->line("  PASS: {$name}");
            $this->passed++;
        }
    }

    /**
     * Build a simple ObjectSchema from the structured assertion keys.
     *
     * @param  array<string, mixed>  $assertions
     */
    private function buildSchemaFromAssertions(array $assertions): ObjectSchema
    {
        /** @var array<string, mixed> $structured */
        $structured = is_array($assertions['structured']) ? $assertions['structured'] : [];

        $properties = [];
        $required = [];

        foreach (array_keys($structured) as $key) {
            $properties[] = new StringSchema($key, $key);
            $required[] = $key;
        }

        return new ObjectSchema(
            name: 'PromptTestSchema',
            description: 'Auto-generated schema from test assertions',
            properties: $properties,
            requiredFields: $required,
        );
    }
}
