<?php

declare(strict_types=1);

namespace KLXM\Scheduler\Orm;

use KLXM\Scheduler\Orm\Attribute\Index;

/**
 * @template T of object
 */
final readonly class EntityMetadata
{
    /**
     * @param class-string<T> $class
     * @param array<string, ColumnMetadata> $columns nach Property-Name
     * @param list<Index> $indexes
     */
    public function __construct(
        public string $class,
        public string $table,
        public array $columns,
        public ColumnMetadata $id,
        public array $indexes,
    ) {}

    public function column(string $property): ColumnMetadata
    {
        return $this->columns[$property]
            ?? throw new OrmException(sprintf('%s hat keine persistente Property "%s".', $this->class, $property));
    }

    public function hasColumn(string $property): bool
    {
        return isset($this->columns[$property]);
    }
}
