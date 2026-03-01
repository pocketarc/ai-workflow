<?php

declare(strict_types=1);

namespace AiWorkflow;

use Prism\Prism\Contracts\Message;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;

class MessageSerializer
{
    /**
     * Serialize a list of Message objects to a JSON-safe array.
     *
     * @param  array<int, Message>  $messages
     * @return list<array<string, mixed>>
     */
    public static function serialize(array $messages): array
    {
        return array_values(array_map(self::serializeMessage(...), $messages));
    }

    /**
     * Deserialize a stored array back into Message objects.
     *
     * @param  list<array<string, mixed>>  $data
     * @return list<Message>
     */
    public static function deserialize(array $data): array
    {
        return array_map(self::deserializeMessage(...), $data);
    }

    /**
     * @return array<string, mixed>
     */
    private static function serializeMessage(Message $message): array
    {
        if ($message instanceof UserMessage) {
            return ['type' => 'user', 'content' => $message->content];
        }

        if ($message instanceof AssistantMessage) {
            return [
                'type' => 'assistant',
                'content' => $message->content,
                'tool_calls' => array_map(fn (ToolCall $toolCall): array => [
                    'id' => $toolCall->id,
                    'name' => $toolCall->name,
                    'arguments' => $toolCall->arguments(),
                ], $message->toolCalls),
            ];
        }

        if ($message instanceof ToolResultMessage) {
            return [
                'type' => 'tool_result',
                'tool_results' => array_map(fn (ToolResult $toolResult): array => [
                    'tool_call_id' => $toolResult->toolCallId,
                    'tool_name' => $toolResult->toolName,
                    'args' => $toolResult->args,
                    'result' => $toolResult->result,
                ], $message->toolResults),
            ];
        }

        if ($message instanceof SystemMessage) {
            return ['type' => 'system', 'content' => $message->content];
        }

        return ['type' => 'unknown', 'class' => $message::class];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function deserializeMessage(array $data): Message
    {
        $type = $data['type'] ?? 'unknown';
        $content = is_string($data['content'] ?? null) ? $data['content'] : '';

        if ($type === 'user') {
            return new UserMessage($content);
        }

        if ($type === 'assistant') {
            /** @var list<array<string, mixed>> $toolCallsData */
            $toolCallsData = is_array($data['tool_calls'] ?? null) ? $data['tool_calls'] : [];

            return new AssistantMessage($content, self::deserializeToolCalls($toolCallsData));
        }

        if ($type === 'tool_result') {
            /** @var list<array<string, mixed>> $toolResultsData */
            $toolResultsData = is_array($data['tool_results'] ?? null) ? $data['tool_results'] : [];

            return new ToolResultMessage(self::deserializeToolResults($toolResultsData));
        }

        if ($type === 'system') {
            return new SystemMessage($content);
        }

        return new UserMessage($content);
    }

    /**
     * @param  list<array<string, mixed>>  $toolCalls
     * @return list<ToolCall>
     */
    private static function deserializeToolCalls(array $toolCalls): array
    {
        return array_map(function (array $toolCallData): ToolCall {
            $id = is_string($toolCallData['id'] ?? null) ? $toolCallData['id'] : '';
            $name = is_string($toolCallData['name'] ?? null) ? $toolCallData['name'] : '';

            /** @var array<string, mixed> $arguments */
            $arguments = is_array($toolCallData['arguments'] ?? null) ? $toolCallData['arguments'] : [];

            return new ToolCall(id: $id, name: $name, arguments: $arguments);
        }, $toolCalls);
    }

    /**
     * @param  list<array<string, mixed>>  $toolResults
     * @return list<ToolResult>
     */
    private static function deserializeToolResults(array $toolResults): array
    {
        return array_map(function (array $toolResultData): ToolResult {
            $toolCallId = is_string($toolResultData['tool_call_id'] ?? null) ? $toolResultData['tool_call_id'] : '';
            $toolName = is_string($toolResultData['tool_name'] ?? null) ? $toolResultData['tool_name'] : '';

            /** @var array<string, mixed> $args */
            $args = is_array($toolResultData['args'] ?? null) ? $toolResultData['args'] : [];

            /** @var array<string, mixed>|float|int|string|null $result */
            $result = $toolResultData['result'] ?? null;

            return new ToolResult(toolCallId: $toolCallId, toolName: $toolName, args: $args, result: $result);
        }, $toolResults);
    }
}
