<?php

declare(strict_types=1);

namespace KLXM\Scheduler\Orm;

use DateTimeZone;
use ReflectionClass;

final class Hydrator
{
    /**
     * @template T of object
     * @param EntityMetadata<T> $metadata
     * @param array<string, scalar|null> $row
     * @return T
     */
    public static function hydrate(EntityMetadata $metadata, array $row): object
    {
        $entity = new ReflectionClass($metadata->class)->newInstanceWithoutConstructor();

        // Zwei Durchläufe: LocalDateTime-Spalten brauchen die bereits gesetzte Zeitzonen-Property.
        $deferred = [];
        foreach ($metadata->columns as $column) {
            if (!array_key_exists($column->column, $row)) {
                continue;
            }
            if (ColumnType::LocalDateTime === $column->type && null !== $column->timezoneProperty) {
                $deferred[] = $column;
                continue;
            }
            $column->reflection->setValue($entity, Converter::toPhp($column, $row[$column->column]));
        }

        foreach ($deferred as $column) {
            $zoneName = $metadata->column((string) $column->timezoneProperty)->reflection->getValue($entity);
            $zone = self::zone(is_string($zoneName) ? $zoneName : null);
            $column->reflection->setValue($entity, Converter::toPhp($column, $row[$column->column], $zone));
        }

        return $entity;
    }

    /**
     * @template T of object
     * @param EntityMetadata<T> $metadata
     * @param T $entity
     * @return array<string, scalar|null> Spaltenname => Datenbankwert, ohne Primärschlüssel
     */
    public static function extract(EntityMetadata $metadata, object $entity): array
    {
        $data = [];
        foreach ($metadata->columns as $column) {
            if ($column->isId) {
                continue;
            }
            if (!$column->reflection->isInitialized($entity)) {
                if ($column->nullable) {
                    $data[$column->column] = null;
                    continue;
                }
                throw new OrmException(sprintf('%s::$%s ist nicht initialisiert.', $metadata->class, $column->property));
            }
            $data[$column->column] = Converter::toDatabase($column, $column->reflection->getValue($entity));
        }

        return $data;
    }

    private static function zone(?string $name): DateTimeZone
    {
        try {
            return new DateTimeZone($name ?? 'UTC');
        } catch (\Throwable) {
            return new DateTimeZone('UTC');
        }
    }
}
