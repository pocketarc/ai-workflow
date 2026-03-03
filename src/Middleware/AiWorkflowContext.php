<?php

declare(strict_types=1);

namespace AiWorkflow\Middleware;

use AiWorkflow\PromptData;
use Prism\Prism\Contracts\Message;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Structured\Response as StructuredResponse;
use Prism\Prism\Text\Response;

class AiWorkflowContext
{
    /**
     * @param  list<Message>  $messages
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public array $messages,
        public PromptData $prompt,
        public string $systemPrompt,
        public string $method,
        public ?ObjectSchema $schema = null,
        public Response|StructuredResponse|null $response = null,
        public array $metadata = [],
    ) {}
}
