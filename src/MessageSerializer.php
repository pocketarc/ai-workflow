<?php

declare(strict_types=1);

namespace AiWorkflow;

use Prism\Prism\Contracts\Message;
use Prism\Prism\ValueObjects\Media\Audio;
use Prism\Prism\ValueObjects\Media\Document;
use Prism\Prism\ValueObjects\Media\Image;
use Prism\Prism\ValueObjects\Media\Media;
use Prism\Prism\ValueObjects\Media\Text;
use Prism\Prism\ValueObjects\Media\Video;
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
            $result = ['type' => 'user', 'content' => $message->content];

            $additionalContent = self::serializeAdditionalContent($message);
            if ($additionalContent !== []) {
                $result['additional_content'] = $additionalContent;
            }

            return $result;
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
     * Serialize additional content from a UserMessage, excluding the auto-appended Text.
     *
     * UserMessage's constructor always appends Text($content) to additionalContent.
     * We strip that trailing Text to avoid duplication on deserialization.
     *
     * @return list<array<string, mixed>>
     */
    private static function serializeAdditionalContent(UserMessage $message): array
    {
        $items = $message->additionalContent;

        // Remove the trailing Text that matches $content (auto-appended by constructor).
        if ($items !== []) {
            $last = end($items);
            if ($last instanceof Text && $last->text === $message->content) {
                array_pop($items);
            }
        }

        if ($items === []) {
            return [];
        }

        return array_values(array_map(self::serializeMediaItem(...), $items));
    }

    /**
     * @return array<string, mixed>
     */
    private static function serializeMediaItem(Text|Image|Document|Media $item): array
    {
        if ($item instanceof Text) {
            return ['media_type' => 'text', 'text' => $item->text];
        }

        if ($item instanceof Document) {
            return array_filter([
                'media_type' => 'document',
                'url' => $item->url(),
                'base64' => $item->base64(),
                'mime_type' => $item->mimeType(),
                'document_title' => $item->documentTitle(),
            ], fn (mixed $v): bool => $v !== null);
        }

        // Image, Audio, Video, or generic Media — determine specific type.
        $mediaType = match (true) {
            $item instanceof Image => 'image',
            $item instanceof Audio => 'audio',
            $item instanceof Video => 'video',
            default => 'media',
        };

        return array_filter([
            'media_type' => $mediaType,
            'url' => $item->url(),
            'base64' => $item->base64(),
            'mime_type' => $item->mimeType(),
        ], fn (mixed $v): bool => $v !== null);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<Text|Image|Document|Media>
     */
    private static function deserializeAdditionalContent(array $items): array
    {
        return array_map(self::deserializeMediaItem(...), $items);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function deserializeMediaItem(array $data): Text|Image|Document|Media
    {
        $mediaType = is_string($data['media_type'] ?? null) ? $data['media_type'] : 'text';

        if ($mediaType === 'text') {
            return new Text(is_string($data['text'] ?? null) ? $data['text'] : '');
        }

        $url = is_string($data['url'] ?? null) ? $data['url'] : null;
        $base64 = is_string($data['base64'] ?? null) ? $data['base64'] : null;
        $mimeType = is_string($data['mime_type'] ?? null) ? $data['mime_type'] : null;

        if ($mediaType === 'document') {
            $title = is_string($data['document_title'] ?? null) ? $data['document_title'] : null;

            $doc = new Document(url: $url, base64: $base64, mimeType: $mimeType);
            if ($title !== null) {
                $doc->setDocumentTitle($title);
            }

            return $doc;
        }

        $class = match ($mediaType) {
            'image' => Image::class,
            'audio' => Audio::class,
            'video' => Video::class,
            default => Media::class,
        };

        return new $class(url: $url, base64: $base64, mimeType: $mimeType);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function deserializeMessage(array $data): Message
    {
        $type = $data['type'] ?? 'unknown';
        $content = is_string($data['content'] ?? null) ? $data['content'] : '';

        if ($type === 'user') {
            /** @var list<array<string, mixed>> $additionalContentData */
            $additionalContentData = is_array($data['additional_content'] ?? null) ? $data['additional_content'] : [];

            $additionalContent = $additionalContentData !== []
                ? self::deserializeAdditionalContent($additionalContentData)
                : [];

            return new UserMessage($content, $additionalContent);
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
