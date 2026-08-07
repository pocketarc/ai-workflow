<?php

declare(strict_types=1);

namespace AiWorkflow\Tests;

use AiWorkflow\AiService;
use AiWorkflow\Exceptions\StructuredValidationException;
use AiWorkflow\PromptData;
use AiWorkflow\SchemaBuilder;
use AiWorkflow\StructuredDataResult;
use AiWorkflow\Tests\Fixtures\Data\AddressData;
use AiWorkflow\Tests\Fixtures\Data\DefaultedData;
use AiWorkflow\Tests\Fixtures\Data\NestedDefaultsData;
use AiWorkflow\Tests\Fixtures\Data\NullableNoDefaultData;
use AiWorkflow\Tests\Fixtures\Data\PersonData;
use AiWorkflow\Tests\Fixtures\Data\SentimentData;
use AiWorkflow\Tests\Fixtures\Data\TeamData;
use AiWorkflow\Tests\Fixtures\Data\TypedSentimentData;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\ArraySchema;
use Prism\Prism\Schema\EnumSchema;
use Prism\Prism\Schema\NumberSchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Testing\StructuredResponseFake;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\Usage;

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

    public function test_nullable_property_is_still_required(): void
    {
        $schema = SchemaBuilder::fromDataClass(TypedSentimentData::class);

        $this->assertSame(['type', 'reason'], $schema->requiredFields);
    }

    public function test_required_lists_every_property(): void
    {
        foreach ([SentimentData::class, PersonData::class, TeamData::class, TypedSentimentData::class, DefaultedData::class] as $dataClass) {
            $schema = SchemaBuilder::fromDataClass($dataClass);
            $propertyNames = array_map(fn ($property) => $property->name(), $schema->properties);

            $this->assertSame($propertyNames, $schema->requiredFields, "required for {$dataClass} must list every key in properties (OpenAI strict mode)");
        }
    }

    public function test_defaulted_property_is_nullable_so_the_model_can_decline(): void
    {
        $schema = SchemaBuilder::fromDataClass(DefaultedData::class);
        $array = $schema->toArray();

        // 'language' is a non-nullable PHP string, widened so its default has a null to fall back on.
        $this->assertSame(['string', 'null'], $array['properties']['language']['type']);
        $this->assertSame(['string', 'null'], $array['properties']['tone']['type']);
        $this->assertSame('string', $array['properties']['sentiment']['type']);
    }

    public function test_strip_nulls_restores_defaults(): void
    {
        $stripped = SchemaBuilder::stripNullsForDefaultedProperties(DefaultedData::class, [
            'sentiment' => 'positive',
            'reason' => null,
            'language' => null,
            'tone' => null,
        ]);

        $this->assertSame(['sentiment' => 'positive'], $stripped);

        $data = DefaultedData::from($stripped);
        $this->assertSame('en', $data->language);
        $this->assertSame('neutral', $data->tone);
        $this->assertNull($data->reason);
    }

    public function test_strip_nulls_keeps_values_the_model_supplied(): void
    {
        $stripped = SchemaBuilder::stripNullsForDefaultedProperties(DefaultedData::class, [
            'sentiment' => 'negative',
            'reason' => 'late delivery',
            'language' => 'fr',
            'tone' => 'sharp',
        ]);

        $data = DefaultedData::from($stripped);
        $this->assertSame('fr', $data->language);
        $this->assertSame('sharp', $data->tone);
        $this->assertSame('late delivery', $data->reason);
    }

    public function test_strip_nulls_recurses_into_nested_data(): void
    {
        $stripped = SchemaBuilder::stripNullsForDefaultedProperties(NestedDefaultsData::class, [
            'name' => 'Jane',
            'address' => ['street' => 'Main St', 'country' => null],
            'previous' => [['street' => 'Old Rd', 'country' => null]],
        ]);

        $data = NestedDefaultsData::from($stripped);

        $this->assertSame('UK', $data->address->country);
        $this->assertSame('UK', $data->previous[0]->country);
    }

    public function test_strip_nulls_keeps_nested_values_the_model_supplied(): void
    {
        $stripped = SchemaBuilder::stripNullsForDefaultedProperties(NestedDefaultsData::class, [
            'name' => 'Jane',
            'address' => ['street' => 'Main St', 'country' => 'FR'],
            'previous' => [['street' => 'Old Rd', 'country' => 'DE']],
        ]);

        $data = NestedDefaultsData::from($stripped);

        $this->assertSame('FR', $data->address->country);
        $this->assertSame('DE', $data->previous[0]->country);
    }

    public function test_send_structured_data_applies_nested_defaults(): void
    {
        Prism::fake([
            StructuredResponseFake::make()
                ->withStructured([
                    'name' => 'Jane',
                    'address' => ['street' => 'Main St', 'country' => null],
                    'previous' => [['street' => 'Old Rd', 'country' => null]],
                ])
                ->withFinishReason(FinishReason::Stop),
        ]);

        $service = app(AiService::class);
        $result = $service->sendStructuredData(
            collect([new UserMessage('Where does Jane live?')]),
            new PromptData(id: 'test', model: 'openrouter:test-model', prompt: 'Extract the address.'),
            NestedDefaultsData::class,
        );

        $this->assertSame('UK', $result->data->address->country);
        $this->assertSame('UK', $result->data->previous[0]->country);
    }

    public function test_strip_nulls_leaves_a_nullable_property_without_a_default(): void
    {
        // No default, so the null is the model's answer rather than a decline.
        $stripped = SchemaBuilder::stripNullsForDefaultedProperties(NullableNoDefaultData::class, [
            'category_id' => null,
            'confidence' => 0,
        ]);

        $this->assertArrayHasKey('category_id', $stripped);
        $this->assertNull(NullableNoDefaultData::from($stripped)->category_id);
    }

    public function test_nested_object_required_lists_every_property(): void
    {
        $schema = SchemaBuilder::fromDataClass(PersonData::class);

        /** @var ObjectSchema $addressSchema */
        $addressSchema = $schema->properties[2];
        $propertyNames = array_map(fn ($property) => $property->name(), $addressSchema->properties);

        $this->assertSame($propertyNames, $addressSchema->requiredFields);
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

    // --- ArrayItemType ---

    public function test_array_without_attribute_defaults_to_string_items(): void
    {
        $schema = SchemaBuilder::fromDataClass(TeamData::class);

        /** @var ArraySchema $tagsSchema */
        $tagsSchema = $schema->properties[1];
        $this->assertInstanceOf(ArraySchema::class, $tagsSchema);
        $this->assertSame('tags', $tagsSchema->name);
        $this->assertInstanceOf(StringSchema::class, $tagsSchema->items);
    }

    public function test_array_with_scalar_item_type(): void
    {
        $schema = SchemaBuilder::fromDataClass(TeamData::class);

        /** @var ArraySchema $scoresSchema */
        $scoresSchema = $schema->properties[2];
        $this->assertInstanceOf(ArraySchema::class, $scoresSchema);
        $this->assertSame('scores', $scoresSchema->name);
        $this->assertInstanceOf(NumberSchema::class, $scoresSchema->items);
    }

    public function test_array_with_data_class_item_type(): void
    {
        $schema = SchemaBuilder::fromDataClass(TeamData::class);

        /** @var ArraySchema $membersSchema */
        $membersSchema = $schema->properties[3];
        $this->assertInstanceOf(ArraySchema::class, $membersSchema);
        $this->assertSame('members', $membersSchema->name);
        $this->assertInstanceOf(ObjectSchema::class, $membersSchema->items);

        /** @var ObjectSchema $itemSchema */
        $itemSchema = $membersSchema->items;
        $this->assertCount(3, $itemSchema->properties);
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

        $this->assertInstanceOf(StructuredDataResult::class, $result);
        $this->assertInstanceOf(SentimentData::class, $result->data);
        $this->assertSame('positive', $result->data->sentiment);
        $this->assertSame(0.95, $result->data->confidence);
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

        $this->assertInstanceOf(StructuredDataResult::class, $result);
        $this->assertInstanceOf(SentimentData::class, $result->data);
        $this->assertSame('negative', $result->data->sentiment);
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

    public function test_send_structured_data_result_includes_response_and_usage(): void
    {
        $usage = new Usage(100, 50, thoughtTokens: 30);

        Prism::fake([
            StructuredResponseFake::make()
                ->withStructured(['sentiment' => 'positive', 'confidence' => 0.95])
                ->withFinishReason(FinishReason::Stop)
                ->withUsage($usage),
        ]);

        $service = app(AiService::class);
        $result = $service->sendStructuredData(
            collect([new UserMessage('Analyze this text')]),
            new PromptData(id: 'test', model: 'openrouter:test-model', prompt: 'Analyze sentiment.'),
            SentimentData::class,
        );

        $this->assertInstanceOf(StructuredDataResult::class, $result);
        $this->assertSame(100, $result->usage->promptTokens);
        $this->assertSame(50, $result->usage->completionTokens);
        $this->assertSame(30, $result->usage->thoughtTokens);
        $this->assertSame($result->response->usage, $result->usage);
        $this->assertSame(FinishReason::Stop, $result->response->finishReason);
    }
}
