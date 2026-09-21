<?php

declare(strict_types=1);

namespace KLXM\Scheduler\Field;

use KLXM\Scheduler\Field\Type;

final class TypeRegistry
{
    /** @var array<string, FieldType> */
    private array $types = [];

    public static function withDefaults(): self
    {
        $registry = new self();
        foreach ([
            new Type\TextType(),
            new Type\TextareaType(),
            new Type\NumberType(),
            new Type\DateType(),
            new Type\TimeType(),
            new Type\SelectType(),
            new Type\RadioType(),
            new Type\CheckboxType(),
            new Type\ColorType(),
            new Type\TagsType(),
            new Type\MediaType(),
            new Type\MediaListType(),
            new Type\LinkType(),
            new Type\LinkListType(),
        ] as $type) {
            $registry->register($type);
        }
        if (Type\YformType::available()) {
            $registry->register(new Type\YformType());
        }

        return $registry;
    }

    public function register(FieldType $type): void
    {
        $this->types[$type->key()] = $type;
    }

    public function has(string $key): bool
    {
        return isset($this->types[$key]);
    }

    public function get(string $key): FieldType
    {
        return $this->types[$key] ?? throw new \InvalidArgumentException(sprintf('Unbekannter Feldtyp "%s".', $key));
    }

    /**
     * @return array<string, FieldType>
     */
    public function all(): array
    {
        return $this->types;
    }
}
