<?php

declare(strict_types=1);

namespace KLXM\Scheduler\Orm;

use rex;
use rex_sql_column;
use rex_sql_index;
use rex_sql_table;

/**
 * Erzeugt und aktualisiert Tabellen direkt aus den Entity-Attributen.
 *
 * Unbekannte Spalten bleiben bewusst stehen: generierte Indexspalten für Custom Fields
 * liegen außerhalb der Entity-Definition.
 */
final class SchemaManager
{
    /**
     * @param list<class-string> $entities
     */
    public static function ensure(array $entities): void
    {
        foreach ($entities as $entity) {
            self::ensureEntity(MetadataFactory::for($entity));
        }
    }

    /**
     * @param list<class-string> $entities
     */
    public static function drop(array $entities): void
    {
        foreach ($entities as $entity) {
            rex_sql_table::get(rex::getTable(MetadataFactory::for($entity)->table))->drop();
        }
    }

    /**
     * @param EntityMetadata<object> $metadata
     */
    private static function ensureEntity(EntityMetadata $metadata): void
    {
        $table = rex_sql_table::get(rex::getTable($metadata->table));
        $table->ensurePrimaryIdColumn();

        foreach ($metadata->columns as $column) {
            if ($column->isId) {
                continue;
            }
            $table->ensureColumn(new rex_sql_column(
                $column->column,
                $column->sqlType(),
                $column->nullable,
                self::defaultFor($column),
            ));
        }

        foreach ($metadata->indexes as $index) {
            $table->ensureIndex(new rex_sql_index(
                $index->name,
                $index->columns,
                $index->unique ? rex_sql_index::UNIQUE : rex_sql_index::INDEX,
            ));
        }

        $table->ensure();
    }

    private static function defaultFor(ColumnMetadata $column): ?string
    {
        if (!$column->hasDefault || null === $column->default) {
            return null;
        }
        if (in_array($column->type, [ColumnType::Text, ColumnType::LongText, ColumnType::Json], true)) {
            return null;
        }

        $value = Converter::toDatabase($column, $column->default);

        return null === $value ? null : (string) $value;
    }
}
