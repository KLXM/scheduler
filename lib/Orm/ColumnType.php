<?php

declare(strict_types=1);

namespace KLXM\Scheduler\Orm;

enum ColumnType: string
{
    /** Typ wird aus dem PHP-Typ der Property abgeleitet. */
    case Auto = 'auto';
    case Int = 'int';
    case Float = 'float';
    case Bool = 'bool';
    case String = 'string';
    case Text = 'text';
    case LongText = 'longtext';
    case Json = 'json';
    case Enum = 'enum';
    case Date = 'date';
    /** Zeitpunkt, in UTC gespeichert. */
    case UtcDateTime = 'utc_datetime';
    /** Wanduhrzeit ohne Umrechnung; die Zeitzone kommt aus einer zweiten Property. */
    case LocalDateTime = 'local_datetime';

    public function sqlType(?int $length): string
    {
        return match ($this) {
            self::Int => 'int(11)',
            self::Float => 'double',
            self::Bool => 'tinyint(1)',
            self::String, self::Enum => 'varchar(' . ($length ?? 191) . ')',
            self::Text => 'text',
            self::LongText, self::Json => 'longtext',
            self::Date => 'date',
            self::UtcDateTime, self::LocalDateTime => 'datetime',
            self::Auto => throw new \LogicException('ColumnType::Auto muss vor der Schemaerzeugung aufgelöst werden.'),
        };
    }
}
