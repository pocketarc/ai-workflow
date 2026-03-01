<?php

declare(strict_types=1);

namespace AiWorkflow\Facades;

use AiWorkflow\AiService;
use AiWorkflow\Models\AiWorkflowExecution;
use Closure;
use Generator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Facade;
use Override;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Streaming\Events\StreamEvent;
use Prism\Prism\Structured\Response as StructuredResponse;
use Prism\Prism\Text\Response;
use Prism\Prism\Tool;

/**
 * @method static Response sendMessages(Collection<int, \Prism\Prism\Contracts\Message> $messages, \AiWorkflow\PromptData $prompt, ?\AiWorkflow\PromptData $extraContext = null, int $steps = 15)
 * @method static StructuredResponse sendStructuredMessages(Collection<int, \Prism\Prism\Contracts\Message> $messages, \AiWorkflow\PromptData $prompt, ObjectSchema $schema, ?string $modelOverride = null)
 * @method static StructuredResponse sendStructuredMessagesWithTools(Collection<int, \Prism\Prism\Contracts\Message> $messages, \AiWorkflow\PromptData $prompt, ObjectSchema $schema)
 * @method static Generator<int, StreamEvent, mixed, void> streamMessages(Collection<int, \Prism\Prism\Contracts\Message> $messages, \AiWorkflow\PromptData $prompt, ?\AiWorkflow\PromptData $extraContext = null, int $steps = 15)
 * @method static void setContext(array<string, mixed> $context)
 * @method static array<string, mixed> getContext()
 * @method static void resolveToolsUsing(Closure $resolver)
 * @method static list<Tool> getTools()
 * @method static void startExecution(string $name, array<string, mixed> $metadata = [])
 * @method static AiWorkflowExecution|null endExecution()
 *
 * @see AiService
 */
class AiWorkflow extends Facade
{
    #[Override]
    protected static function getFacadeAccessor(): string
    {
        return AiService::class;
    }
}
