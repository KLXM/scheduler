<?php

declare(strict_types=1);

namespace KLXM\Scheduler\Orm\Attribute;

use Attribute;
use KLXM\Scheduler\Orm\ColumnType;

/**
 * Markiert eine Property als persistente Spalte.
 *
 * Ohne Angaben werden Spaltenname (snake_case) und Typ aus der Property abgeleitet.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class Column
{
    public function __construct(
        public ?string $name = null,
        public ColumnType $type = ColumnType::Auto,
        public ?int $length = null,
        /** Property mit der IANA-Zeitzone, in der ein LocalDateTime-Wert zu lesen ist. */
        public ?string $timezoneProperty = null,
    ) {}
}
