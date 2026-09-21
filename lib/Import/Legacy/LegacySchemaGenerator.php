<?php

declare(strict_types=1);

namespace KLXM\Scheduler\Import\Legacy;

use KLXM\Scheduler\Domain\Enum\SchemaTarget;
use KLXM\Scheduler\Field\Schema;

/**
 * Erzeugt aus den YAML-Definitionen von forcal 6 einmalig ein Builder-Schema.
 *
 * @internal
 */
final class LegacySchemaGenerator
{
    /** Die mit forcal 6 ausgelieferten Standarddefinitionen, falls keine eigene Datei existiert. */
    private const array DEFAULTS = [
        'entries' => [
            'langfields' => [['panel' => 'images', 'label_de' => 'Sprachbezogene Bildelemente', 'fields' => [
                ['name' => 'lang_image', 'type' => 'media', 'label_de' => 'Bild'],
                ['name' => 'lang_images', 'type' => 'medialist', 'label_de' => 'Bilder'],
            ]]],
            'fields' => [
                ['name' => 'image', 'type' => 'media', 'label_de' => 'Bildelement'],
                ['name' => 'file', 'type' => 'media', 'label_de' => 'Datei Anhang'],
            ],
        ],
    ];

    private const array FILES = ['entries' => SchemaTarget::Event, 'categories' => SchemaTarget::Calendar, 'venues' => SchemaTarget::Location];

    /** @var list<string> */
    private array $coreNames = [];

    /**
     * @param string $definitionPath Datenordner von forcal 6 mit den custom_*.yml-Dateien
     */
    public function __construct(
        private readonly string $definitionPath,
    ) {}

    /**
     * @param list<string> $usedColumns Custom-Spalten, die in den Altdaten tatsächlich vorkommen
     * @param list<string> $coreNames Namen fester Kernfelder; gleichnamige Altfelder gehen in den Kern über
     * @return Schema|null null, wenn es nichts zu übernehmen gibt
     */
    public function generate(SchemaTarget $target, array $usedColumns, array $coreNames = []): ?Schema
    {
        $this->coreNames = $coreNames;
        $file = array_search($target, self::FILES, true);
        $definition = $this->load((string) $file) ?? self::DEFAULTS[$file] ?? [];

        $nodes = [
            ...$this->convert((array) ($definition['fields'] ?? []), false),
            ...$this->convert((array) ($definition['langfields'] ?? []), true),
        ];
        // Standarddefinitionen nur übernehmen, soweit die Spalten wirklich Daten tragen.
        if (null === $this->load((string) $file)) {
            $nodes = $this->onlyUsed($nodes, $usedColumns);
        }

        return [] === $nodes ? null : Schema::fromArray(['nodes' => $nodes]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function load(string $file): ?array
    {
        // forcal 6 legte eigene Definitionen als custom_<name>.yml ab, ältere Versionen als <name>.yml.
        $base = rtrim($this->definitionPath, '/') . '/';
        $path = array_find([$base . 'custom_' . $file . '.yml', $base . $file . '.yml'], static fn (string $candidate): bool => is_readable($candidate));
        if (null === $path) {
            return null;
        }
        try {
            $data = \rex_string::yamlDecode((string) file_get_contents($path));
        } catch (\Throwable) {
            return null;
        }

        return [] === $data ? null : $data;
    }

    /**
     * @param array<int|string, mixed> $items
     * @return list<array<string, mixed>>
     */
    private function convert(array $items, bool $translatable): array
    {
        $nodes = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            if (isset($item['panel']) || (isset($item['fields']) && !isset($item['type']))) {
                $children = $this->convert((array) ($item['fields'] ?? []), $translatable);
                if ([] !== $children) {
                    $nodes[] = ['type' => 'fieldset', 'label' => $this->label($item), 'children' => $children];
                }
                continue;
            }
            $node = $this->field($item, $translatable);
            if (null !== $node) {
                $nodes[] = $node;
            }
        }

        return $nodes;
    }

    /**
     * @param array<string, mixed> $field
     * @return array<string, mixed>|null
     */
    private function field(array $field, bool $translatable): ?array
    {
        $name = strtolower(trim((string) ($field['name'] ?? '')));
        if (1 !== preg_match('/^[a-z][a-z0-9_]*$/', $name) || in_array($name, $this->coreNames, true)) {
            return null;
        }

        $legacyType = (string) ($field['type'] ?? 'text');
        if ('tagging' === $legacyType && 'tags' === $name) {
            return null; // wird zu den festen Schlagwörtern des Termins
        }
        $hasChoices = isset($field['options']) || isset($field['qry']);
        $type = match ($legacyType) {
            'media', 'medialist', 'link', 'linklist', 'textarea', 'text' => $legacyType,
            'select', 'selectsql', 'checkboxsql' => 'select',
            'radio', 'radiosql' => 'radio',
            'checkbox' => $hasChoices ? 'select' : 'checkbox',
            'tagging' => 'tags',
            default => 'text',
        };

        $options = [];
        if (is_array($field['options'] ?? null)) {
            $options['choices'] = implode("\n", array_map(static fn ($value, $label): string => $value . '|' . $label, array_keys($field['options']), $field['options']));
        }
        if (is_string($field['qry'] ?? null) && '' !== trim($field['qry'])) {
            // forcal 6 erwartete die Spalten "id" und "name"; der Builder liest Wert und Beschriftung der Reihe nach.
            $options['sql'] = 'SELECT id, name FROM (' . rtrim(trim($field['qry']), ';') . ') forcal_legacy';
        }
        if (in_array($legacyType, ['checkbox', 'checkboxsql'], true) && $hasChoices) {
            $options['multiple'] = true;
        }
        if ('tags' === $type && isset($field['max_tags'])) {
            $options['max'] = (int) $field['max_tags'];
        }

        return array_filter([
            'type' => $type,
            'name' => $name,
            'label' => $this->label($field),
            'translatable' => $translatable,
            'options' => $options,
        ], static fn (mixed $value): bool => [] !== $value && false !== $value);
    }

    /**
     * @param array<string, mixed> $item
     */
    private function label(array $item): string
    {
        return (string) ($item['label_de'] ?? $item['label_all'] ?? $item['label_en'] ?? $item['label'] ?? $item['name'] ?? $item['panel'] ?? '');
    }

    /**
     * @param list<array<string, mixed>> $nodes
     * @param list<string> $usedColumns
     * @return list<array<string, mixed>>
     */
    private function onlyUsed(array $nodes, array $usedColumns): array
    {
        $result = [];
        foreach ($nodes as $node) {
            if (isset($node['children'])) {
                $node['children'] = $this->onlyUsed($node['children'], $usedColumns);
                if ([] !== $node['children']) {
                    $result[] = $node;
                }
            } elseif (in_array($node['name'] ?? '', $usedColumns, true)) {
                $result[] = $node;
            }
        }

        return $result;
    }
}
