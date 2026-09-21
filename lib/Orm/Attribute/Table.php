<?php

declare(strict_types=1);

namespace KLXM\Scheduler\Orm\Attribute;

use Attribute;

/**
 * Ordnet eine Entity einer Datenbanktabelle zu. Der Name wird ohne REDAXO-Präfix angegeben.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Table
{
    public function __construct(
        public string $name,
    ) {}
}
