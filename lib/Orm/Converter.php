<?php

declare(strict_types=1);

namespace KLXM\Scheduler\Orm;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Wandelt Werte zwischen PHP-Typen und ihrer Datenbankdarstellung.
 */
final class Converter
{
    private const string SQL_DATETIME = 'Y-m-d H:i:s';

    public static function toDatabase(ColumnMetadata $column, mixed $value): string|int|float|null
    {
        if (null === $value) {
            return null;
        }

        return match ($column->type) {
            ColumnType::Int => (int) $value,
            ColumnType::Float => (float) $value,
            ColumnType::Bool => $value ? 1 : 0,
            ColumnType::String, ColumnType::Text, ColumnType::LongText => (string) $value,
            ColumnType::Json => json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ColumnType::Enum => $value instanceof \BackedEnum ? $value->value : $value,
            ColumnType::Date => self::dateTime($column, $value)->format('Y-m-d'),
            ColumnType::UtcDateTime => self::dateTime($column, $value)
                ->setTimezone(new DateTimeZone('UTC'))
                ->format(self::SQL_DATETIME),
            ColumnType::LocalDateTime => self::dateTime($column, $value)->format(self::SQL_DATETIME),
            ColumnType::Auto => throw new OrmException('Unaufgelöster Spaltentyp.'),
        };
    }

    public static function toPhp(ColumnMetadata $column, mixed $value, ?DateTimeZone $localZone = null): mixed
    {
        if (null === $value) {
            return null;
        }

        return match ($column->type) {
            ColumnType::Int => (int) $value,
            ColumnType::Float => (float) $value,
            ColumnType::Bool => (bool) $value,
            ColumnType::String, ColumnType::Text, ColumnType::LongText => (string) $value,
            ColumnType::Json => '' === $value ? [] : json_decode((string) $value, true, 512, JSON_THROW_ON_ERROR),
            ColumnType::Enum => null !== $column->enumClass
                ? $column->enumClass::from(is_numeric($value) && 'int' === (string) new \ReflectionEnum($column->enumClass)->getBackingType() ? (int) $value : $value)
                : $value,
            ColumnType::Date, ColumnType::UtcDateTime => new DateTimeImmutable((string) $value, new DateTimeZone('UTC')),
            ColumnType::LocalDateTime => new DateTimeImmutable((string) $value, $localZone ?? new DateTimeZone('UTC')),
            ColumnType::Auto => throw new OrmException('Unaufgelöster Spaltentyp.'),
        };
    }

    private static function dateTime(ColumnMetadata $column, mixed $value): DateTimeImmutable
    {
        if (!$value instanceof DateTimeInterface) {
            throw new OrmException(sprintf('Property "%s" erwartet ein DateTime-Objekt.', $column->property));
        }

        return DateTimeImmutable::createFromInterface($value);
    }
}
