<?php

declare(strict_types=1);

namespace AiWorkflow\Tests;

use AiWorkflow\AiService;
use AiWorkflow\Exceptions\StructuredValidationException;
use AiWorkflow\PromptData;
use AiWorkflow\SchemaBuilder;
use AiWorkflow\Tests\Fixtures\Data\AddressData;
use AiWorkflow\Tests\Fixtures\Data\PersonData;
use AiWorkflow\Tests\Fixtures\Data\SentimentData;
use AiWorkflow\Tests\Fixtures\Data\TypedSentimentData;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\EnumSchema;
use Prism\Prism\Schema\NumberSchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Testing\StructuredResponseFake;
use Prism\Prism\ValueObjects\Messages\UserMessage;

class SchemaBuilderTest extends TestCase
{
    // --- SchemaBuilder ---

    public function test_generates_schema_from_simple_data_class(): void
    {
        $schema = SchemaBuilder::fromDataClass(SentimentData::class);

        $this->assertInstanceOf(ObjectSchema::class, $schema);
        $this->assertSame('SentimentData', $schema->name);
        $this->assertCount(2, $schema->properties);
        $this->assertSame(['sentiment', 'confidence'], $schema->requiredFields);
    }

    public function test_maps_string_to_string_schema(): void
    {
        $schema = SchemaBuilder::fromDataClass(SentimentData::class);

        $this->assertInstanceOf(StringSchema::class, $schema->properties[0]);
        $this->assertSame('sentiment', $schema->properties[0]->name);
    }

    public function test_maps_float_to_number_schema(): void
    {
        $schema = SchemaBuilder::fromDataClass(SentimentData::class);

        $this->assertInstanceOf(NumberSchema::class, $schema->properties[1]);
        $this->assertSame('confidence', $schema->properties[1]->name);
    }

    public function test_maps_int_to_number_schema(): void
    {
        $schema = SchemaBuilder::fromDataClass(PersonData::class);

        $this->assertInstanceOf(NumberSchema::class, $schema->properties[1]);
        $this->assertSame('age', $schema->properties[1]->name);
    }

    public function test_description_attribute_used_for_descriptions(): void
    {
        $schema = SchemaBuilder::fromDataClass(SentimentData::class);

        $this->assertSame('The detected sentiment: positive, negative, or neutral', $schema->properties[0]->description);
        $this->assertSame('Confidence score from 0.0 to 1.0', $schema->properties[1]->description);
    }

    public function test_falls_back_to_property_name_without_description(): void
    {
        $schema = SchemaBuilder::fromDataClass(AddressData::class);

        $this->assertSame('street', $schema->properties[0]->description);
        $this->assertSame('city', $schema->properties[1]->description);
    }

    public function test_maps_backed_enum_to_enum_schema(): void
    {
        $schema = SchemaBuilder::fromDataClass(TypedSentimentData::class);

        $this->assertInstanceOf(EnumSchema::class, $schema->properties[0]);
        $this->assertSame('type', $schema->properties[0]->name);
        $this->assertSame(['positive', 'negative', 'neutral'], $schema->properties[0]->options);
    }

    public function test_nullable_property_not_required(): void
    {
        $schema = SchemaBuilder::fromDataClass(TypedSentimentData::class);

        // 'type' is required, 'reason' is nullable with default — not required
        $this->assertSame(['type'], $schema->requiredFields);
    }

    public function test_nullable_property_sets_nullable_flag(): void
    {
        $schema = SchemaBuilder::fromDataClass(TypedSentimentData::class);

        /** @var StringSchema $reasonSchema */
        $reasonSchema = $schema->properties[1];
        $this->assertInstanceOf(StringSchema::class, $reasonSchema);
        $this->assertTrue($reasonSchema->nullable);
    }

    public function test_nested_data_class_maps_to_object_schema(): void
    {
        $schema = SchemaBuilder::fromDataClass(PersonData::class);

        /** @var ObjectSchema $addressSchema */
        $addressSchema = $schema->properties[2];
        $this->assertInstanceOf(ObjectSchema::class, $addressSchema);
        $this->assertSame('address', $addressSchema->name);
        $this->assertCount(2, $addressSchema->properties);
        $this->assertSame(['street', 'city'], $addressSchema->requiredFields);
    }

    public function test_schema_to_array_produces_valid_json_schema(): void
    {
        $schema = SchemaBuilder::fromDataClass(SentimentData::class);
        $array = $schema->toArray();

        $this->assertSame('object', $array['type']);
        $this->assertArrayHasKey('properties', $array);
        $this->assertArrayHasKey('sentiment', $array['properties']);
        $this->assertArrayHasKey('confidence', $array['properties']);
        $this->assertSame(['sentiment', 'confidence'], $array['required']);
    }

    // --- sendStructuredData ---

    public function test_send_structured_data_returns_validated_instance(): void
    {
        Prism::fake([
            StructuredResponseFake::make()
                ->withStructured(['sentiment' => 'positive', 'confidence' => 0.95])
                ->withFinishReason(FinishReason::Stop),
        ]);

        $service = app(AiService::class);
        $result = $service->sendStructuredData(
            collect([new UserMessage('Analyze this text')]),
            new PromptData(id: 'test', model: 'openrouter:test-model', prompt: 'Analyze sentiment.'),
            SentimentData::class,
        );

        $this->assertInstanceOf(SentimentData::class, $result);
        $this->assertSame('positive', $result->sentiment);
        $this->assertSame(0.95, $result->confidence);
    }

    public function test_send_structured_data_retries_on_validation_failure(): void
    {
        Prism::fake([
            // First attempt: missing required field
            StructuredResponseFake::make()
                ->withStructured(['confidence' => 0.5])
                ->withFinishReason(FinishReason::Stop),
            // Second attempt: valid
            StructuredResponseFake::make()
                ->withStructured(['sentiment' => 'negative', 'confidence' => 0.8])
                ->withFinishReason(FinishReason::Stop),
        ]);

        $service = app(AiService::class);
        $result = $service->sendStructuredData(
            collect([new UserMessage('Analyze')]),
            new PromptData(id: 'test', model: 'openrouter:test-model', prompt: 'Analyze.'),
            SentimentData::class,
        );

        $this->assertInstanceOf(SentimentData::class, $result);
        $this->assertSame('negative', $result->sentiment);
    }

    public function test_send_structured_data_throws_after_max_attempts(): void
    {
        Prism::fake([
            StructuredResponseFake::make()
                ->withStructured(['confidence' => 0.5])
                ->withFinishReason(FinishReason::Stop),
            StructuredResponseFake::make()
                ->withStructured(['confidence' => 0.6])
                ->withFinishReason(FinishReason::Stop),
        ]);

        $this->expectException(StructuredValidationException::class);

        $service = app(AiService::class);
        $service->sendStructuredData(
            collect([new UserMessage('Analyze')]),
            new PromptData(id: 'test', model: 'openrouter:test-model', prompt: 'Analyze.'),
            SentimentData::class,
            maxAttempts: 2,
        );
    }

    public function test_structured_validation_exception_tracks_attempts(): void
    {
        Prism::fake([
            StructuredResponseFake::make()
                ->withStructured(['confidence' => 0.5])
                ->withFinishReason(FinishReason::Stop),
            StructuredResponseFake::make()
                ->withStructured(['confidence' => 0.5])
                ->withFinishReason(FinishReason::Stop),
            StructuredResponseFake::make()
                ->withStructured(['confidence' => 0.5])
                ->withFinishReason(FinishReason::Stop),
        ]);

        try {
            $service = app(AiService::class);
            $service->sendStructuredData(
                collect([new UserMessage('Analyze')]),
                new PromptData(id: 'test', model: 'openrouter:test-model', prompt: 'Analyze.'),
                SentimentData::class,
                maxAttempts: 3,
            );
            $this->fail('Expected StructuredValidationException');
        } catch (StructuredValidationException $e) {
            $this->assertSame(3, $e->attempts);
            $this->assertNotNull($e->getPrevious());
        }
    }
}
