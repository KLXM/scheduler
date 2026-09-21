<?php

declare(strict_types=1);

namespace KLXM\Scheduler\Orm\Attribute;

use Attribute;

/**
 * Definiert einen Index über eine oder mehrere Spalten (Spaltennamen, nicht Property-Namen).
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class Index
{
    /**
     * @param non-empty-list<string> $columns
     */
    public function __construct(
        public string $name,
        public array $columns,
        public bool $unique = false,
    ) {}
}
