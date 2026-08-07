<?php

declare(strict_types=1);

namespace AiWorkflow;

use AiWorkflow\Attributes\ArrayItemType;
use AiWorkflow\Attributes\Description;
use BackedEnum;
use Prism\Prism\Contracts\Schema;
use Prism\Prism\Schema\ArraySchema;
use Prism\Prism\Schema\BooleanSchema;
use Prism\Prism\Schema\EnumSchema;
use Prism\Prism\Schema\NumberSchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use ReflectionClass;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionUnionType;
use RuntimeException;
use Spatie\LaravelData\Data;

class SchemaBuilder
{
    /**
     * Generate an ObjectSchema from a Spatie LaravelData class.
     *
     * @param  class-string<Data>  $dataClass
     */
    public static function fromDataClass(string $dataClass): ObjectSchema
    {
        if (! class_exists(Data::class)) {
            throw new RuntimeException('spatie/laravel-data is required to use SchemaBuilder. Install it with: composer require spatie/laravel-data');
        }

        $reflection = new ReflectionClass($dataClass);
        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            throw new RuntimeException("Data class {$dataClass} has no constructor");
        }

        $properties = [];
        $requiredFields = [];

        foreach ($constructor->getParameters() as $param) {
            $name = $param->getName();
            $type = $param->getType();
            $description = self::getDescription($param);

            [$schema] = self::resolveType($type, $name, $description, $param);
            $properties[] = $schema;

            // Strict mode rejects a schema that lists a key in `properties` but not in `required`.
            $requiredFields[] = $name;
        }

        return new ObjectSchema(
            name: $reflection->getShortName(),
            description: $reflection->getShortName(),
            properties: $properties,
            requiredFields: $requiredFields,
        );
    }

    /**
     * Drop the nulls a model returned for properties that declare a default, so PHP applies the
     * default. `sendStructuredData()` calls this; call it yourself if you hydrate the structured
     * response from `sendStructuredMessages()`.
     *
     * @param  class-string<Data>  $dataClass
     * @param  array<mixed>|null  $structured
     * @return array<mixed>|null
     */
    public static function stripNullsForDefaultedProperties(string $dataClass, ?array $structured): ?array
    {
        if ($structured === null) {
            return null;
        }

        $constructor = (new ReflectionClass($dataClass))->getConstructor();

        if ($constructor === null) {
            return $structured;
        }

        foreach ($constructor->getParameters() as $param) {
            $name = $param->getName();

            if (! $param->isDefaultValueAvailable()) {
                continue;
            }

            if (array_key_exists($name, $structured) && $structured[$name] === null) {
                unset($structured[$name]);
            }
        }

        return $structured;
    }

    /**
     * @return array{Schema, bool} [schema, nullable]
     */
    private static function resolveType(?\ReflectionType $type, string $name, string $description, ?ReflectionParameter $param = null): array
    {
        if ($type === null) {
            throw new RuntimeException("Property '{$name}' has no type declaration");
        }

        if ($type instanceof ReflectionIntersectionType) {
            throw new RuntimeException("Property '{$name}' has an intersection type that cannot be mapped to a schema");
        }

        $nullable = false;

        if ($type instanceof ReflectionUnionType) {
            $nonNullTypes = [];
            foreach ($type->getTypes() as $unionMember) {
                if ($unionMember instanceof ReflectionNamedType && $unionMember->getName() !== 'null') {
                    $nonNullTypes[] = $unionMember;
                }
            }

            if (count($nonNullTypes) !== 1) {
                throw new RuntimeException("Property '{$name}' has a union type that cannot be mapped to a schema");
            }

            $namedType = $nonNullTypes[0];
            $nullable = true;
        } else {
            $namedType = $type;
        }

        if (! $namedType instanceof ReflectionNamedType) {
            throw new RuntimeException("Property '{$name}' has an unsupported type");
        }

        $nullable = $nullable || $namedType->allowsNull();

        // Strict mode cannot leave a key out, so a defaulted property needs a null to return.
        $nullable = $nullable || ($param !== null && $param->isDefaultValueAvailable());

        $schema = self::mapNamedType($namedType->getName(), $name, $description, $nullable, $param);

        return [$schema, $nullable];
    }

    private static function mapNamedType(string $typeName, string $name, string $description, bool $nullable, ?ReflectionParameter $param = null): Schema
    {
        return match ($typeName) {
            'string' => new StringSchema($name, $description, $nullable),
            'int', 'float' => new NumberSchema($name, $description, $nullable),
            'bool' => new BooleanSchema($name, $description, $nullable),
            'array' => new ArraySchema($name, $description, self::resolveArrayItemSchema($param), $nullable),
            default => self::mapClassType($typeName, $name, $description, $nullable),
        };
    }

    private static function resolveArrayItemSchema(?ReflectionParameter $param): Schema
    {
        if ($param === null) {
            return new StringSchema('item', 'Array item');
        }

        $attributes = $param->getAttributes(ArrayItemType::class);

        if ($attributes === []) {
            return new StringSchema('item', 'Array item');
        }

        /** @var ArrayItemType $itemType */
        $itemType = $attributes[0]->newInstance();
        $typeName = $itemType->type;

        return match ($typeName) {
            'string' => new StringSchema('item', 'Array item'),
            'int', 'float' => new NumberSchema('item', 'Array item'),
            'bool' => new BooleanSchema('item', 'Array item'),
            default => self::resolveArrayItemClassType($typeName),
        };
    }

    private static function resolveArrayItemClassType(string $typeName): Schema
    {
        if (class_exists($typeName) && is_subclass_of($typeName, Data::class)) {
            $nested = self::fromDataClass($typeName);

            return new ObjectSchema(
                'item',
                'Array item',
                $nested->properties,
                $nested->requiredFields,
            );
        }

        return new StringSchema('item', 'Array item');
    }

    private static function mapClassType(string $typeName, string $name, string $description, bool $nullable): Schema
    {
        if (! class_exists($typeName) && ! enum_exists($typeName)) {
            throw new RuntimeException("Property '{$name}' type '{$typeName}' cannot be mapped to a schema");
        }

        if (is_subclass_of($typeName, Data::class)) {
            $nested = self::fromDataClass($typeName);

            return new ObjectSchema(
                $name,
                $description,
                $nested->properties,
                $nested->requiredFields,
                nullable: $nullable,
            );
        }

        if (is_subclass_of($typeName, BackedEnum::class)) {
            /** @var list<string|int> $options */
            $options = array_map(
                fn (BackedEnum $case): string|int => $case->value,
                $typeName::cases(),
            );

            return new EnumSchema($name, $description, $options, $nullable);
        }

        throw new RuntimeException("Property '{$name}' type '{$typeName}' cannot be mapped to a schema");
    }

    private static function getDescription(ReflectionParameter $param): string
    {
        $attributes = $param->getAttributes(Description::class);

        if ($attributes !== []) {
            /** @var Description $instance */
            $instance = $attributes[0]->newInstance();

            return $instance->text;
        }

        return $param->getName();
    }
}
