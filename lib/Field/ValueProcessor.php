<?php

declare(strict_types=1);

namespace KLXM\Scheduler\Field;

use KLXM\Scheduler\I18n;
/**
 * Liest Custom-Field-Werte aus einem Request, normalisiert und prüft sie gegen das Schema.
 * Unbekannte Schlüssel werden verworfen: gespeichert wird nur, was das Schema kennt.
 */
final class ValueProcessor
{
    public function __construct(
        private readonly TypeRegistry $types,
    ) {}

    /**
     * @param array<string, mixed> $raw Inhalt von custom[...]
     * @param array<int|string, mixed> $rawTranslated Inhalt von custom_lang[...]
     * @param list<int> $clangIds Start-Sprache zuerst
     * @param array<string, mixed> $existing bisherige Werte; Felder, die das Schema nicht mehr kennt, bleiben erhalten
     */
    public function process(Schema $schema, array $raw, array $rawTranslated, array $clangIds, array $existing = []): ProcessedValues
    {
        // Bestehende Werte, die das Schema nicht (mehr) kennt, bleiben unangetastet: ein entferntes Feld
        // oder importierte Altdaten gehen beim nächsten Speichern nicht verloren.
        $values = array_diff_key($existing, $schema->fields());
        $translated = [];
        $errors = [];
        $primaryClang = $clangIds[0] ?? 1;

        $conditions = $schema->conditions();

        foreach ($schema->fields() as $name => $node) {
            // Ausgeblendete Felder werden weder gespeichert noch auf Pflicht geprüft.
            if (!$this->isVisible($conditions[$name] ?? [], $raw)) {
                continue;
            }
            unset($values[$name]);
            if ('repeater' === $node->type) {
                [$values[$name], $error] = $this->repeater($node, $raw[$name] ?? []);
                if (null !== $error) {
                    $errors[$name] = $error;
                }
                continue;
            }
            if (!$this->types->has($node->type)) {
                continue;
            }
            $type = $this->types->get($node->type);

            if ($node->translatable) {
                foreach ($clangIds as $clangId) {
                    $value = $type->normalize($rawTranslated[$clangId][$name] ?? null, $node);
                    if (!$type->isEmpty($value)) {
                        $translated[$clangId][$name] = $value;
                    }
                    $error = $this->check($type, $node, $value, $clangId === $primaryClang);
                    if (null !== $error && !isset($errors[$name])) {
                        $errors[$name] = $error;
                    }
                }
                continue;
            }

            $value = $type->normalize($raw[$name] ?? null, $node);
            if (!$type->isEmpty($value)) {
                $values[$name] = $value;
            }
            $error = $this->check($type, $node, $value, true);
            if (null !== $error) {
                $errors[$name] = $error;
            }
        }

        return new ProcessedValues($values, $translated, $errors);
    }

    /**
     * @param list<array{field: string, operator: string, value: string}> $conditions
     * @param array<string, mixed> $raw
     */
    private function isVisible(array $conditions, array $raw): bool
    {
        foreach ($conditions as $condition) {
            $actual = $raw[$condition['field']] ?? '';
            $actual = is_array($actual) ? implode(',', array_filter($actual, is_scalar(...))) : (string) $actual;
            $matches = match ($condition['operator']) {
                '!=' => $actual !== $condition['value'],
                'empty' => '' === $actual || '0' === $actual,
                'not_empty' => '' !== $actual && '0' !== $actual,
                default => $actual === $condition['value'],
            };
            if (!$matches) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array{list<array<string, mixed>>, ?string}
     */
    private function repeater(FieldNode $node, mixed $rawRows): array
    {
        $rows = [];
        $error = null;
        foreach (is_array($rawRows) ? $rawRows : [] as $rawRow) {
            if (!is_array($rawRow)) {
                continue;
            }
            $row = [];
            $rowError = null;
            foreach ($node->children as $child) {
                if (!$child->isField() || !$this->types->has($child->type)) {
                    continue;
                }
                $type = $this->types->get($child->type);
                $value = $type->normalize($rawRow[$child->name] ?? null, $child);
                if (!$type->isEmpty($value)) {
                    $row[$child->name] = $value;
                }
                $rowError ??= $this->check($type, $child, $value, true);
            }
            // Vollständig leere Zeilen gelten als nicht vorhanden und lösen keine Pflichtfehler aus.
            if ([] !== $row) {
                $rows[] = $row;
                $error ??= $rowError;
            }
        }

        $min = (int) $node->option('min', 0);
        $max = (int) $node->option('max', 0);
        if (count($rows) < $min) {
            $error ??= I18n::t('field_error_min_rows', $min);
        }
        if ($max > 0 && count($rows) > $max) {
            $rows = array_slice($rows, 0, $max);
        }

        return [$rows, $error];
    }

    private function check(FieldType $type, FieldNode $node, mixed $value, bool $enforceRequired): ?string
    {
        if ($type->isEmpty($value)) {
            return ($node->required && $enforceRequired) ? I18n::t('field_error_required', '' !== $node->label ? $node->label : $node->name) : null;
        }

        return $type->validate($value, $node);
    }
}
