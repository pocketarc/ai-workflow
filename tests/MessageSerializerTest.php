<?php

declare(strict_types=1);

namespace AiWorkflow\Tests;

use AiWorkflow\MessageSerializer;
use Prism\Prism\ValueObjects\Media\Audio;
use Prism\Prism\ValueObjects\Media\Document;
use Prism\Prism\ValueObjects\Media\Image;
use Prism\Prism\ValueObjects\Media\Text;
use Prism\Prism\ValueObjects\Media\Video;
use Prism\Prism\ValueObjects\Messages\UserMessage;

class MessageSerializerTest extends TestCase
{
    public function test_roundtrip_image_base64(): void
    {
        $image = Image::fromBase64('iVBORw0KGgo=', 'image/png');
        $message = new UserMessage('Describe this image', [$image]);

        $serialized = MessageSerializer::serialize([$message]);
        $deserialized = MessageSerializer::deserialize($serialized);

        $this->assertCount(1, $deserialized);
        $this->assertInstanceOf(UserMessage::class, $deserialized[0]);
        $this->assertSame('Describe this image', $deserialized[0]->content);

        $images = $deserialized[0]->images();
        $this->assertCount(1, $images);
        $this->assertSame('iVBORw0KGgo=', $images[0]->base64());
        $this->assertSame('image/png', $images[0]->mimeType());
    }

    public function test_roundtrip_image_url(): void
    {
        $image = Image::fromUrl('https://example.com/photo.jpg', 'image/jpeg');
        $message = new UserMessage('What is this?', [$image]);

        $serialized = MessageSerializer::serialize([$message]);
        $deserialized = MessageSerializer::deserialize($serialized);

        $this->assertCount(1, $deserialized);
        $images = $deserialized[0]->images();
        $this->assertCount(1, $images);
        $this->assertSame('https://example.com/photo.jpg', $images[0]->url());
        $this->assertSame('image/jpeg', $images[0]->mimeType());
    }

    public function test_roundtrip_document_with_title(): void
    {
        $doc = Document::fromBase64('JVBERi0xLjQ=', 'application/pdf', 'Invoice');
        $message = new UserMessage('Summarize this', [$doc]);

        $serialized = MessageSerializer::serialize([$message]);
        $deserialized = MessageSerializer::deserialize($serialized);

        $this->assertCount(1, $deserialized);
        $documents = $deserialized[0]->documents();
        $this->assertCount(1, $documents);
        $this->assertSame('JVBERi0xLjQ=', $documents[0]->base64());
        $this->assertSame('application/pdf', $documents[0]->mimeType());
        $this->assertSame('Invoice', $documents[0]->documentTitle());
    }

    public function test_roundtrip_audio(): void
    {
        $audio = Audio::fromBase64('AAAA', 'audio/mp3');
        $message = new UserMessage('Transcribe this', [$audio]);

        $serialized = MessageSerializer::serialize([$message]);
        $deserialized = MessageSerializer::deserialize($serialized);

        $this->assertCount(1, $deserialized);
        $audios = $deserialized[0]->audios();
        $this->assertCount(1, $audios);
        $this->assertSame('AAAA', $audios[0]->base64());
        $this->assertSame('audio/mp3', $audios[0]->mimeType());
    }

    public function test_roundtrip_video(): void
    {
        $video = Video::fromUrl('https://example.com/clip.mp4', 'video/mp4');
        $message = new UserMessage('Describe this video', [$video]);

        $serialized = MessageSerializer::serialize([$message]);
        $deserialized = MessageSerializer::deserialize($serialized);

        $this->assertCount(1, $deserialized);
        $videos = $deserialized[0]->videos();
        $this->assertCount(1, $videos);
        $this->assertSame('https://example.com/clip.mp4', $videos[0]->url());
    }

    public function test_roundtrip_mixed_media(): void
    {
        $image = Image::fromBase64('iVBORw0KGgo=', 'image/png');
        $doc = Document::fromUrl('https://example.com/doc.pdf', 'Report');
        $extra = new Text('Extra context here');
        $message = new UserMessage('Analyze all of this', [$image, $doc, $extra]);

        $serialized = MessageSerializer::serialize([$message]);
        $deserialized = MessageSerializer::deserialize($serialized);

        $this->assertCount(1, $deserialized);
        $restored = $deserialized[0];
        $this->assertInstanceOf(UserMessage::class, $restored);
        $this->assertSame('Analyze all of this', $restored->content);
        $this->assertCount(1, $restored->images());
        $this->assertCount(1, $restored->documents());
    }

    public function test_backward_compat_without_additional_content(): void
    {
        // Old serialized format — no additional_content key.
        $oldData = [
            ['type' => 'user', 'content' => 'Hello world'],
        ];

        $deserialized = MessageSerializer::deserialize($oldData);

        $this->assertCount(1, $deserialized);
        $this->assertInstanceOf(UserMessage::class, $deserialized[0]);
        $this->assertSame('Hello world', $deserialized[0]->content);
    }

    public function test_text_only_message_has_no_additional_content_key(): void
    {
        $message = new UserMessage('Just text');

        $serialized = MessageSerializer::serialize([$message]);

        $this->assertArrayNotHasKey('additional_content', $serialized[0]);
        $this->assertSame('user', $serialized[0]['type']);
        $this->assertSame('Just text', $serialized[0]['content']);
    }

    public function test_extra_text_parts_are_preserved(): void
    {
        $extra = new Text('Additional context');
        $message = new UserMessage('Main question', [$extra]);

        $serialized = MessageSerializer::serialize([$message]);

        $this->assertArrayHasKey('additional_content', $serialized[0]);
        $this->assertCount(1, $serialized[0]['additional_content']);
        $this->assertSame('text', $serialized[0]['additional_content'][0]['media_type']);
        $this->assertSame('Additional context', $serialized[0]['additional_content'][0]['text']);
    }
}
