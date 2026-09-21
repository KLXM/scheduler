<?php

declare(strict_types=1);

namespace KLXM\Scheduler\Field;

use KLXM\Scheduler\I18n;
/**
 * Das Custom-Field-Schema eines Ziels (Termin, Kalender, Ort) als Baum aus Knoten.
 */
final class Schema
{
    public const int FORMAT = 1;

    private const string NAME_PATTERN = '/^[a-z][a-z0-9_]{0,62}$/';

    /**
     * @param list<FieldNode> $nodes
     */
    public function __construct(
        public readonly array $nodes = [],
    ) {}

    /**
     * @param array<string, mixed> $definition
     */
    public static function fromArray(array $definition): self
    {
        return new self(array_values(array_map(
            FieldNode::fromArray(...),
            array_filter((array) ($definition['nodes'] ?? []), is_array(...)),
        )));
    }

    /**
     * @return array{format: int, nodes: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return ['format' => self::FORMAT, 'nodes' => array_map(static fn (FieldNode $n): array => $n->toArray(), $this->nodes)];
    }

    public bool $isEmpty {
        get => [] === $this->nodes;
    }

    /**
     * Alle Felder der obersten Werte-Ebene: Repeater zählen als ein Feld, ihre Kinder nicht.
     *
     * @return array<string, FieldNode> nach Feldname
     */
    public function fields(): array
    {
        $fields = [];
        $walk = static function (array $nodes) use (&$walk, &$fields): void {
            foreach ($nodes as $node) {
                if ($node->isField() || 'repeater' === $node->type) {
                    $fields[$node->name] = $node;
                } else {
                    $walk($node->children);
                }
            }
        };
        $walk($this->nodes);

        return $fields;
    }

    /**
     * Sichtbarkeitsbedingungen je Feld inklusive der Bedingungen aller umgebenden Strukturelemente.
     *
     * @return array<string, list<array{field: string, operator: string, value: string}>>
     */
    public function conditions(): array
    {
        $conditions = [];
        $walk = static function (array $nodes, array $inherited) use (&$walk, &$conditions): void {
            foreach ($nodes as $node) {
                $own = null === $node->visibleIf ? $inherited : [...$inherited, $node->visibleIf];
                if ($node->isField() || 'repeater' === $node->type) {
                    $conditions[$node->name] = $own;
                } else {
                    $walk($node->children, $own);
                }
            }
        };
        $walk($this->nodes, []);

        return $conditions;
    }

    /**
     * Prüft das Schema selbst: Namen, Eindeutigkeit, Kollision mit Kernfeldern, bekannte Typen.
     *
     * @param list<string> $reservedNames Namen der festen Kernfelder des Ziels
     * @return list<string> Fehlermeldungen
     */
    public function validate(TypeRegistry $types, array $reservedNames): array
    {
        $errors = [];
        $seen = [];

        $check = static function (array $nodes, bool $inRepeater) use (&$check, &$errors, &$seen, $types, $reservedNames): void {
            $local = [];
            foreach ($nodes as $node) {
                $isValueNode = $node->isField() || 'repeater' === $node->type;
                if ($isValueNode) {
                    $label = '' !== $node->label ? $node->label : $node->name;
                    if (1 !== preg_match(self::NAME_PATTERN, $node->name)) {
                        $errors[] = I18n::t('schema_error_name', $label);
                    } elseif (!$inRepeater && in_array($node->name, $reservedNames, true)) {
                        $errors[] = I18n::t('schema_error_reserved', $node->name);
                    } elseif ($inRepeater ? isset($local[$node->name]) : isset($seen[$node->name])) {
                        $errors[] = I18n::t('schema_error_duplicate', $node->name);
                    }
                    $inRepeater ? $local[$node->name] = true : $seen[$node->name] = true;
                }

                if ($node->isField()) {
                    if (!$types->has($node->type)) {
                        $errors[] = I18n::t('schema_error_type', $node->name, $node->type);
                    } elseif ($inRepeater && !$types->get($node->type)->allowedInRepeater()) {
                        $errors[] = I18n::t('schema_error_repeater_type', $node->name, $node->type);
                    }
                    continue;
                }

                if ('repeater' === $node->type && $inRepeater) {
                    $errors[] = I18n::t('schema_error_nested');
                    continue;
                }
                $check($node->children, $inRepeater || 'repeater' === $node->type);
            }
        };
        $check($this->nodes, false);

        return $errors;
    }
}
