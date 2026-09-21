<?php

declare(strict_types=1);

namespace KLXM\Scheduler\Orm;

use BackedEnum;
use DateTimeInterface;
use KLXM\Scheduler\Orm\Attribute\Column;
use KLXM\Scheduler\Orm\Attribute\Id;
use KLXM\Scheduler\Orm\Attribute\Index;
use KLXM\Scheduler\Orm\Attribute\Table;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;

/**
 * Liest die ORM-Attribute einer Entity-Klasse einmalig per Reflection aus.
 */
final class MetadataFactory
{
    /** @var array<class-string, EntityMetadata<object>> */
    private static array $cache = [];

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return EntityMetadata<T>
     */
    public static function for(string $class): EntityMetadata
    {
        /** @var EntityMetadata<T> */
        return self::$cache[$class] ??= self::build($class);
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return EntityMetadata<T>
     */
    private static function build(string $class): EntityMetadata
    {
        $reflection = new ReflectionClass($class);
        $table = ($reflection->getAttributes(Table::class)[0] ?? null)?->newInstance()
            ?? throw new OrmException(sprintf('%s hat kein #[Table]-Attribut.', $class));

        $columns = [];
        $id = null;
        foreach ($reflection->getProperties() as $property) {
            $attribute = ($property->getAttributes(Column::class)[0] ?? null)?->newInstance();
            if (null === $attribute || $property->isStatic()) {
                continue;
            }
            $meta = self::column($class, $property, $attribute);
            $columns[$property->getName()] = $meta;
            if ($meta->isId) {
                $id = $meta;
            }
        }

        if (null === $id) {
            throw new OrmException(sprintf('%s hat keine #[Id]-Property.', $class));
        }

        $indexes = array_map(
            static fn ($attribute) => $attribute->newInstance(),
            $reflection->getAttributes(Index::class),
        );

        return new EntityMetadata($class, $table->name, $columns, $id, $indexes);
    }

    private static function column(string $class, ReflectionProperty $property, Column $attribute): ColumnMetadata
    {
        $type = $property->getType();
        if (!$type instanceof ReflectionNamedType) {
            throw new OrmException(sprintf('%s::$%s braucht einen einfachen Typ.', $class, $property->getName()));
        }

        $phpType = $type->getName();
        $enumClass = is_subclass_of($phpType, BackedEnum::class) ? $phpType : null;
        $columnType = ColumnType::Auto === $attribute->type
            ? self::infer($class, $property->getName(), $phpType, null !== $enumClass)
            : $attribute->type;

        $isPromoted = $property->isPromoted();
        $hasDefault = !$isPromoted && $property->hasDefaultValue();

        return new ColumnMetadata(
            property: $property->getName(),
            column: $attribute->name ?? self::snakeCase($property->getName()),
            type: $columnType,
            nullable: $type->allowsNull(),
            isId: [] !== $property->getAttributes(Id::class),
            length: $attribute->length,
            enumClass: $enumClass,
            timezoneProperty: $attribute->timezoneProperty,
            default: $hasDefault ? $property->getDefaultValue() : null,
            hasDefault: $hasDefault,
            reflection: $property,
        );
    }

    private static function infer(string $class, string $property, string $phpType, bool $isEnum): ColumnType
    {
        return match (true) {
            $isEnum => ColumnType::Enum,
            'int' === $phpType => ColumnType::Int,
            'float' === $phpType => ColumnType::Float,
            'bool' === $phpType => ColumnType::Bool,
            'string' === $phpType => ColumnType::String,
            'array' === $phpType => ColumnType::Json,
            is_a($phpType, DateTimeInterface::class, true) => ColumnType::UtcDateTime,
            default => throw new OrmException(sprintf('Typ "%s" von %s::$%s ist nicht abbildbar.', $phpType, $class, $property)),
        };
    }

    public static function snakeCase(string $name): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $name));
    }
}
