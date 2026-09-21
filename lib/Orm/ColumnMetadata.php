<?php

declare(strict_types=1);

namespace KLXM\Scheduler\Orm;

use ReflectionProperty;

final readonly class ColumnMetadata
{
    /**
     * @param class-string<\BackedEnum>|null $enumClass
     */
    public function __construct(
        public string $property,
        public string $column,
        public ColumnType $type,
        public bool $nullable,
        public bool $isId,
        public ?int $length,
        public ?string $enumClass,
        public ?string $timezoneProperty,
        public mixed $default,
        public bool $hasDefault,
        public ReflectionProperty $reflection,
    ) {}

    public function sqlType(): string
    {
        return $this->isId ? 'int(11) unsigned' : $this->type->sqlType($this->length);
    }
}
