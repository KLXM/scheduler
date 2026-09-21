<?php

declare(strict_types=1);

namespace KLXM\Scheduler\Field;

/**
 * Ein Knoten im Schema: Strukturelement (tab, fieldset, columns, column, repeater) oder Feld.
 */
final readonly class FieldNode
{
    public const array STRUCTURE = ['tab', 'fieldset', 'columns', 'column', 'repeater'];

    /**
     * @param array<string, mixed> $options typspezifische Einstellungen
     * @param list<FieldNode> $children
     * @param array{field: string, operator: string, value: string}|null $visibleIf
     */
    public function __construct(
        public string $type,
        public string $name = '',
        public string $label = '',
        public string $help = '',
        public bool $required = false,
        public bool $translatable = false,
        public bool $filterable = false,
        public bool $ical = false,
        public array $options = [],
        public array $children = [],
        public ?array $visibleIf = null,
    ) {}

    public function isField(): bool
    {
        return !in_array($this->type, self::STRUCTURE, true);
    }

    public function option(string $name, mixed $default = null): mixed
    {
        $value = $this->options[$name] ?? null;

        return (null === $value || '' === $value) ? $default : $value;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $visibleIf = $data['visibleIf'] ?? null;
        if (!is_array($visibleIf) || '' === trim((string) ($visibleIf['field'] ?? ''))) {
            $visibleIf = null;
        }

        return new self(
            type: (string) ($data['type'] ?? 'text'),
            name: (string) ($data['name'] ?? ''),
            label: (string) ($data['label'] ?? ''),
            help: (string) ($data['help'] ?? ''),
            required: (bool) ($data['required'] ?? false),
            translatable: (bool) ($data['translatable'] ?? false),
            filterable: (bool) ($data['filterable'] ?? false),
            ical: (bool) ($data['ical'] ?? false),
            options: is_array($data['options'] ?? null) ? $data['options'] : [],
            children: array_values(array_map(self::fromArray(...), array_filter((array) ($data['children'] ?? []), is_array(...)))),
            visibleIf: null === $visibleIf ? null : [
                'field' => (string) $visibleIf['field'],
                'operator' => in_array($visibleIf['operator'] ?? '=', ['=', '!=', 'empty', 'not_empty'], true) ? (string) ($visibleIf['operator'] ?? '=') : '=',
                'value' => (string) ($visibleIf['value'] ?? ''),
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = ['type' => $this->type];
        if ('' !== $this->name) {
            $data['name'] = $this->name;
        }
        if ('' !== $this->label) {
            $data['label'] = $this->label;
        }
        if ('' !== $this->help) {
            $data['help'] = $this->help;
        }
        foreach (['required', 'translatable', 'filterable', 'ical'] as $flag) {
            if ($this->{$flag}) {
                $data[$flag] = true;
            }
        }
        if ([] !== $this->options) {
            $data['options'] = $this->options;
        }
        if (null !== $this->visibleIf) {
            $data['visibleIf'] = $this->visibleIf;
        }
        if ([] !== $this->children || !$this->isField()) {
            $data['children'] = array_map(static fn (self $child): array => $child->toArray(), $this->children);
        }

        return $data;
    }
}
