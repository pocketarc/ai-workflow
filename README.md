# AI Workflow

Production-ready AI workflows for Laravel. Wraps [Prism PHP](https://github.com/prism-php/prism) with retry logic, fallback models, finish reason monitoring, YAML-based prompt management, request logging, and a replay engine for evals.

## Installation

```bash
composer require pocketarc/ai-workflow
```

Publish the config file:

```bash
php artisan vendor:publish --tag=ai-workflow-config
```

To enable request logging, also publish and run the migrations:

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
---

You are a helpful assistant that answers questions concisely.
```

- `model` (required): The model identifier in `provider:model` format (e.g., `openrouter:google/gemini-3-pro-preview`, `anthropic:claude-opus-4.5`).
- `fallback_model` (optional): If structured decoding fails, retry with this model. Same `provider:model` format.

The prompt's `id` is derived from the filename. A file at `resources/prompts/my_prompt.md` is `my_prompt`.

Load prompts via the service or facade:

```php
use AiWorkflow\Facades\Prompt;

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

    Tool::as('get_ticket')
        ->for('Retrieve a support ticket by ID.')
        ->withStringParameter('id', 'The ticket ID')
        ->using(function (string $id) use ($context): string {
            $customer = $context['customer'] ?? null;
            // ... retrieve ticket scoped to customer
        }),
]);
```

Set context before making calls to pass runtime data to your tools:

```php
$aiService->setContext(['customer' => $customer]);
$response = $aiService->sendMessages($messages, $prompt);
```

## Request Logging

When enabled, every AI call is recorded to the database with enough detail to replay it: system prompt, messages, model, provider, schema, response, token usage, and duration.

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

### Events

Two events are dispatched after every AI call, regardless of whether logging is enabled:

- `AiWorkflowRequestCompleted` — prompt, method, model, finish reason, usage, duration, execution ID.
- `AiWorkflowRequestFailed` — prompt, method, model, exception, duration, execution ID.

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
