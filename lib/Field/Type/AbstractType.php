<?php

declare(strict_types=1);

namespace KLXM\Scheduler\Field\Type;

use KLXM\Scheduler\I18n;
use KLXM\Scheduler\Field\FieldNode;
use KLXM\Scheduler\Field\FieldType;

abstract class AbstractType implements FieldType
{
    public function options(): array
    {
        return [];
    }

    public function allowedInRepeater(): bool
    {
        return true;
    }

    public function normalize(mixed $raw, FieldNode $node): mixed
    {
        return is_scalar($raw) ? trim((string) $raw) : '';
    }

    public function validate(mixed $value, FieldNode $node): ?string
    {
        return null;
    }

    public function isEmpty(mixed $value): bool
    {
        return null === $value || '' === $value || [] === $value || false === $value;
    }

    public function toText(mixed $value, FieldNode $node): string
    {
        return is_array($value) ? implode(', ', array_map(strval(...), array_filter($value, is_scalar(...)))) : (string) (is_scalar($value) ? $value : '');
    }

    protected function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Auswahlmöglichkeiten aus der Option "choices" (eine Zeile je Eintrag: wert|Beschriftung)
     * oder aus der Option "sql" (erste Spalte Wert, zweite Beschriftung).
     *
     * @return array<string, string>
     */
    protected function choices(FieldNode $node): array
    {
        $choices = [];
        foreach (preg_split('/\R/', (string) $node->option('choices', '')) ?: [] as $line) {
            $line = trim($line);
            if ('' === $line) {
                continue;
            }
            [$value, $label] = array_pad(array_map(trim(...), explode('|', $line, 2)), 2, null);
            $choices[(string) $value] = $label ?? (string) $value;
        }

        $sql = trim((string) $node->option('sql', ''));
        if ('' !== $sql && 1 === preg_match('/^\s*select\s/i', $sql) && class_exists(\rex_sql::class)) {
            try {
                foreach (\rex_sql::factory()->getArray($sql, [], \PDO::FETCH_NUM) as $row) {
                    $choices[(string) $row[0]] = (string) ($row[1] ?? $row[0]);
                }
            } catch (\Throwable) {
                // Eine kaputte Abfrage darf das Formular nicht sprengen; die Auswahl bleibt leer.
            }
        }

        return $choices;
    }

    /** @return list<array{name: string, label: string, type: 'text'|'number'|'textarea'|'checkbox'|'select', choices?: array<string, string>, help?: string}> */
    protected function choiceOptions(): array
    {
        return [
            ['name' => 'choices', 'label' => I18n::t('fieldopt_choices'), 'type' => 'textarea', 'help' => I18n::t('fieldopt_choices_help')],
            ['name' => 'sql', 'label' => I18n::t('fieldopt_sql'), 'type' => 'textarea', 'help' => I18n::t('fieldopt_sql_help')],
        ];
    }
}
