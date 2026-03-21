# AI Workflow

Production-ready AI workflows for Laravel. Wraps [Prism PHP](https://github.com/prism-php/prism) with retry logic, fallback models, finish reason monitoring, YAML-based prompt management with Mustache templating, request logging with tagging, middleware pipeline, caching, multimodal support, eval framework, and Laravel Data integration.

## Installation

```bash
composer require pocketarc/ai-workflow
```

Publish the config file:

```bash
php artisan vendor:publish --tag=ai-workflow-config
```

**No database is required for core functionality.** Sending AI requests, prompt management, caching, middleware, streaming, and retry logic all work without migrations. A database is only needed if you enable request logging (`AI_WORKFLOW_LOGGING=true`) or use the eval framework.

To enable request logging or evals, publish and run the migrations:

```bash
php artisan vendor:publish --tag=ai-workflow-migrations
php artisan migrate
```

## Prompt Files

Prompts live as Markdown files with YAML front-matter in your configured `prompts_path` (default: `resources/prompts/`).

```markdown
---
model: openrouter:google/gemini-3-pro-preview
fallback_model: openrouter:openai/gpt-5.2
tags: [classification, intent]
cache_ttl: 3600
---

You are a helpful assistant that answers questions concisely.
```

Front-matter fields:
- `model` (required): Model identifier in `provider:model` format (e.g. `openrouter:google/gemini-3-pro-preview`, `anthropic:claude-opus-4.5`).
- `fallback_model` (optional): If structured decoding fails, retry with this model. Same `provider:model` format.
- `tags` (optional): Array of string tags stored with each request for filtering.
- `cache_ttl` (optional): Cache responses for this many seconds. Omit to disable caching.

The prompt's `id` is derived from the filename. A file at `resources/prompts/my_prompt.md` is `my_prompt`.

### Mustache Templating

Prompts support Mustache variables and conditionals:

```markdown
---
model: openrouter:anthropic/claude-4-opus
---

You are helping {{ customer_name }} with their {{ product }} subscription.
{{#is_vip}}
This is a VIP customer. Provide priority support.
{{/is_vip}}
```

```php
use AiWorkflow\Facades\Prompt;

$prompt = Prompt::load('support', [
    'customer_name' => 'Jane',
    'product' => 'Pro',
    'is_vip' => true,
]);
```

Load prompts without variables as before:

```php
$prompt = Prompt::load('my_prompt');
```

## Usage

### Text Responses

Send messages and get a text response with tool-calling support:

```php
use AiWorkflow\AiService;
use AiWorkflow\Facades\Prompt;
use Prism\Prism\ValueObjects\Messages\UserMessage;

$aiService = app(AiService::class);

$response = $aiService->sendMessages(
    collect([new UserMessage('What is the weather like?')]),
    Prompt::load('chat'),
);

echo $response->text;
```

### Structured Responses

Get structured JSON output matching a schema:

```php
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Schema\NumberSchema;

$schema = new ObjectSchema(
    name: 'analysis',
    description: 'Ticket analysis',
    properties: [
        new StringSchema('summary', 'A brief summary'),
        new NumberSchema('priority', 'Priority from 1-5'),
    ],
    requiredFields: ['summary', 'priority'],
);

$response = $aiService->sendStructuredMessages(
    collect([new UserMessage('Analyze this ticket...')]),
    Prompt::load('analyze_ticket'),
    $schema,
);

$data = $response->structured;
// ['summary' => '...', 'priority' => 3]
```

### Structured Responses with Laravel Data

If you have `spatie/laravel-data` installed, you can use Data classes directly. The package generates the schema from the class, validates the response, and retries with feedback on validation failure:

```php
use AiWorkflow\Attributes\Description;
use Spatie\LaravelData\Data;

class SentimentAnalysis extends Data
{
    public function __construct(
        #[Description('The detected sentiment: positive, negative, or neutral')]
        public readonly string $sentiment,
        #[Description('Confidence score from 0.0 to 1.0')]
        public readonly float $confidence,
    ) {}
}

$result = $aiService->sendStructuredData(
    collect([new UserMessage('Analyze the sentiment of: "I love this product!"')]),
    Prompt::load('sentiment'),
    SentimentAnalysis::class,
);

// $result is a validated SentimentAnalysis instance
echo $result->sentiment;   // "positive"
echo $result->confidence;  // 0.95
```

On validation failure, the package appends the error to the conversation and retries up to `$maxAttempts` (default 3).

### Streaming

Stream text responses as a generator of events:

```php
$stream = $aiService->streamMessages(
    collect([new UserMessage('Tell me a story')]),
    Prompt::load('chat'),
);

foreach ($stream as $event) {
    if ($event instanceof \Prism\Prism\Streaming\Events\TextDeltaEvent) {
        echo $event->delta;
    }
}
```

Streaming does not support automatic retries (this is inherent to how streaming APIs work).

### Extra Context

Pass a second prompt as shared context that gets prepended to the system prompt:

```php
$response = $aiService->sendMessages(
    $messages,
    Prompt::load('respond_to_customer'),
    extraContext: Prompt::load('shared_context'),
);
```

## Tool Registration

Register tools that the AI can call during text conversations:

```php
// In your AppServiceProvider::boot()
use AiWorkflow\AiService;
use Prism\Prism\Facades\Tool;

$aiService = app(AiService::class);

$aiService->resolveToolsUsing(fn (array $context) => [
    Tool::as('get_weather')
        ->for('Get current weather conditions.')
        ->withStringParameter('city', 'The city name')
        ->using(fn (string $city): string => "Weather in {$city}: sunny, 20°C"),
]);
```

Set context before making calls to pass runtime data to your tools:

```php
$aiService->setContext(['customer' => $customer]);
$response = $aiService->sendMessages($messages, $prompt);
```

## Request Tagging

Tags help you categorize and filter logged requests. Set them in prompt front-matter and/or at runtime:

```yaml
---
model: openrouter:anthropic/claude-4
tags: [classification, intent]
---
```

```php
$aiService->setTags(['billing', 'priority']);
```

Tags from both sources are merged and deduplicated. Query with custom builder scopes:

```php
use AiWorkflow\Models\AiWorkflowRequest;

AiWorkflowRequest::query()->withTag('classification')->get();
AiWorkflowRequest::query()->withAnyTag(['classification', 'intent'])->get();
AiWorkflowRequest::query()->byModel('claude-4')->successful()->get();
AiWorkflowRequest::query()->errors()->get();
```

## Request Logging

When enabled, every AI call is recorded to the database with enough detail to replay it: system prompt, messages, model, provider, schema, response, token usage, duration, and tags.

Enable logging in your `.env`:

```
AI_WORKFLOW_LOGGING=true
```

### Execution Grouping

Group related AI calls under a named execution:

```php
$aiService->startExecution('work_ticket', ['ticket_id' => $ticket->id]);

$aiService->sendMessages($messages, Prompt::load('decide_action'));
$aiService->sendMessages($messages, Prompt::load('generate_response'));
$aiService->sendStructuredMessages($messages, Prompt::load('judge_response'), $schema);

$execution = $aiService->endExecution();
// All three calls are linked to this execution.
```

Query executions and get aggregate token usage:

```php
use AiWorkflow\Models\AiWorkflowExecution;

$execution = AiWorkflowExecution::query()->byName('work_ticket')->recent()->first();
$execution->totalInputTokens();
$execution->totalOutputTokens();
$execution->totalTokens();
$execution->totalDurationMs();
$execution->requestCount();
```

### Events

Two events are dispatched after every AI call, regardless of whether logging is enabled:

- `AiWorkflowRequestCompleted` — prompt, method, model, finish reason, usage, duration, execution ID.
- `AiWorkflowRequestFailed` — prompt, method, model, exception, duration, execution ID.

### Sentry Integration

A ready-to-use listener adds Sentry breadcrumbs for AI requests. Register in your `EventServiceProvider`:

```php
use AiWorkflow\Events\AiWorkflowRequestCompleted;
use AiWorkflow\Events\AiWorkflowRequestFailed;
use AiWorkflow\Listeners\SentrySpanListener;

protected $listen = [
    AiWorkflowRequestCompleted::class => [SentrySpanListener::class . '@handleCompleted'],
    AiWorkflowRequestFailed::class => [SentrySpanListener::class . '@handleFailed'],
];
```

No hard dependency on Sentry — the listener is a no-op if Sentry is not installed.

## Caching

Responses can be cached per-prompt using a content-addressable key derived from the request parameters. Set `cache_ttl` in the prompt front-matter:

```yaml
---
model: openrouter:anthropic/claude-4
cache_ttl: 3600
---
```

Enable caching globally in your `.env`:

```
AI_WORKFLOW_CACHE=true
AI_WORKFLOW_CACHE_STORE=redis  # optional, defaults to your app's default cache store
```

Cache hits skip the API call entirely and do not create log records.

## Middleware

Add before/after hooks to all AI requests using a middleware pipeline.

### Global Middleware

Register middleware in your config:

```php
// config/ai-workflow.php
'middleware' => [
    App\Middleware\LogRequestMetrics::class,
],
```

### Instance Middleware

Add middleware per-instance:

```php
$aiService->addMiddleware(new App\Middleware\LogRequestMetrics());
```

### Writing Middleware

Implement `AiWorkflowMiddleware`:

```php
use AiWorkflow\Middleware\AiWorkflowContext;
use AiWorkflow\Middleware\AiWorkflowMiddleware;

class LogRequestMetrics implements AiWorkflowMiddleware
{
    public function handle(AiWorkflowContext $context, Closure $next): AiWorkflowContext
    {
        // Before the AI request
        $start = microtime(true);

        $context = $next($context);

        // After the AI request
        logger()->info('AI request took ' . (microtime(true) - $start) . 's');

        return $context;
    }
}
```

### Guardrails

Abstract base classes for input and output validation:

```php
use AiWorkflow\Middleware\InputGuardrail;
use AiWorkflow\Middleware\AiWorkflowContext;

class PiiDetectionGuardrail extends InputGuardrail
{
    protected function validate(AiWorkflowContext $context): void
    {
        // Throw GuardrailViolationException if PII is detected in messages
    }
}
```

`InputGuardrail` validates before the request; `OutputGuardrail` validates after. Both throw `GuardrailViolationException` on failure.

## Replay Engine

The replay engine lets you re-run recorded AI requests with different models or updated prompts. This is the foundation for evals.

```php
use AiWorkflow\AiWorkflowReplayer;

$replayer = app(AiWorkflowReplayer::class);

// Replay exactly as recorded
$result = $replayer->replay($request);

// Replay with a different model
$result = $replayer->replay($request, model: 'anthropic:claude-4');

// Replay with the latest prompt from disk (uses the stored prompt_id to load)
$result = $replayer->replay($request, useCurrentPrompts: true);

// Both: latest prompt + different model
$result = $replayer->replay($request, useCurrentPrompts: true, model: 'anthropic:claude-4');

// Compare one request across multiple models
$results = $replayer->replayAcrossModels($request, [
    'openrouter:google/gemini-3-pro',
    'anthropic:claude-4',
    'openrouter:openai/gpt-5.2',
]);
// Returns array keyed by model name.

// Replay an entire execution — each request loads its own prompt via prompt_id
$results = $replayer->replayExecution($execution, useCurrentPrompts: true);
```

## Eval Framework

Evaluate AI outputs by replaying recorded requests from curated datasets across models with pluggable judges.

The workflow: run an AI action, verify the response is correct, add the execution to a named dataset, then eval that dataset against different models to see which ones produce equivalent results.

### Building a Dataset

Datasets are collections of known-good executions. Use execution grouping to track AI calls, then add verified executions to a dataset:

```php
// In your action, group AI calls under an execution:
$aiService->startExecution('decide_action #42', ['ticket_id' => 42]);
$response = $aiService->sendStructuredMessages($messages, $prompt, $schema);
$execution = $aiService->endExecution();
// $execution->id is the UUID you'll reference
```

```bash
# After verifying the response was correct, add it to a dataset:
php artisan eval:add decide-actions abc-123-uuid

# List all datasets
php artisan eval:list

# Show executions in a dataset
php artisan eval:show decide-actions

# Remove an execution from a dataset
php artisan eval:remove decide-actions abc-123-uuid
```

### Running Evals

```bash
php artisan eval:run decide-actions \
    --models=openrouter:google/gemini-3-pro,openrouter:openai/gpt-5.2 \
    --judge=App\\Eval\\MyJudge
```

This replays every request in the dataset against each model, judges the results, and displays a per-model score table.

### Writing a Judge

Implement `AiWorkflowEvalJudge`:

```php
use AiWorkflow\Eval\AiWorkflowEvalJudge;
use AiWorkflow\Eval\AiWorkflowEvalResult;
use AiWorkflow\Models\AiWorkflowRequest;
use Prism\Prism\Text\Response;
use Prism\Prism\Structured\Response as StructuredResponse;

class MyJudge implements AiWorkflowEvalJudge
{
    public function judge(AiWorkflowRequest $originalRequest, Response|StructuredResponse $response): AiWorkflowEvalResult
    {
        // Compare the new response against the original recorded response
        // Return a score from 0.0 to 1.0
        return new AiWorkflowEvalResult(score: 0.9, details: ['reasoning' => '...']);
    }
}
```

The package includes `AiJudge` — an AI-powered judge that semantically compares original and new responses (e.g. `{"payer": "John"}` vs `{"payer": "john"}` scores high). For domain-specific evaluation, implement your own judge with custom scoring logic.

### Running Evals in Code

```php
use AiWorkflow\Eval\AiWorkflowEvalRunner;
use AiWorkflow\Models\AiWorkflowEvalDataset;

$runner = app(AiWorkflowEvalRunner::class);
$dataset = AiWorkflowEvalDataset::query()->where('name', 'decide-actions')->firstOrFail();

$evalRun = $runner->run(
    name: 'Decision eval',
    requests: $dataset->requests(),
    models: ['openrouter:anthropic/claude-4', 'openrouter:google/gemini-3-pro'],
    judge: app(MyJudge::class),
);

$evalRun->averageScore();                                    // overall
$evalRun->averageScoreForModel('openrouter:anthropic/claude-4'); // per model
```

## Prompt Testing

Run YAML-defined test cases against prompts to verify AI outputs.

### Test File Format

Test files live alongside prompts in a `tests/` subdirectory:

```
resources/prompts/
  classify_intent.md
  tests/
    classify_intent.yaml
```

```yaml
variables:
  company_name: "Test Corp"

cases:
  - name: "Billing question"
    messages:
      - role: user
        content: "How do I update my credit card?"
    assert:
      structured:
        intent: "billing"
      contains: "billing"

  - name: "Multiple keywords"
    messages:
      - role: user
        content: "I need help with my account password"
    assert:
      contains:
        - "account"
        - "password"
```

### Running Tests

```bash
# Test a specific prompt
php artisan ai-workflow:prompt-test classify_intent

# Test all prompts that have test files
php artisan ai-workflow:prompt-test

# Override the model
php artisan ai-workflow:prompt-test classify_intent --model=anthropic:claude-4
```

## Retry Behaviour

All requests automatically retry on transient failures with random jitter (±25%) to prevent thundering herd:

- **HTTP 429** (rate limit): waits ~30 seconds before retry.
- **HTTP 5xx** (server error): exponential backoff (~attempt x 2 seconds).
- **Connection errors**: linear backoff (~attempt x 1 second).
- **3 retries** by default, configurable via `ai-workflow.retry.times`.

Jitter can be disabled by setting `ai-workflow.retry.jitter` to `false`.

If all retries are exhausted, a `RetriesExhaustedException` is thrown with the retry count and original exception.

### Fallback Models

If a structured request fails to decode JSON (the model produced invalid output), the package automatically retries with the `fallback_model` if one is configured in the prompt's front-matter.

## Finish Reason Handling

After each AI response, the finish reason is checked:

| Finish Reason               | Behaviour                                                                                |
|-----------------------------|------------------------------------------------------------------------------------------|
| `Stop`, `ToolCalls`         | Success — response returned normally.                                                    |
| `Unknown`, `Error`, `Other` | Transient issue — throws `PrismException` so callers can skip gracefully.                |
| `Length`, `ContentFilter`   | Degraded — reports to your error tracker via `report()`, but still returns the response. |

## Testing

The package works with Prism's built-in faking:

```php
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\TextResponseFake;

Prism::fake([
    TextResponseFake::make()
        ->withText('Mocked response')
        ->withFinishReason(FinishReason::Stop),
]);

// Your code that calls AiService will receive the fake response.
```

## Development

```bash
docker compose up -d devtools
docker compose exec devtools composer install
docker compose exec devtools ./vendor/bin/pint          # Code style
docker compose exec devtools ./vendor/bin/phpstan analyse --memory-limit=1G  # Static analysis
docker compose exec devtools ./vendor/bin/phpunit       # Tests
```
