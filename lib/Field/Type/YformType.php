<?php

declare(strict_types=1);

namespace KLXM\Scheduler\Field\Type;

use KLXM\Scheduler\I18n;
use KLXM\Scheduler\Field\FieldContext;
use KLXM\Scheduler\Field\FieldNode;
use rex_addon;
use rex_sql;

/**
 * Verknüpft einen Datensatz (oder mehrere) aus einer YForm-Tabelle. Neue Datensätze lassen sich
 * direkt aus dem Formular heraus im YForm-Popup anlegen. Gespeichert werden die IDs.
 *
 * Nur verfügbar, wenn das Addon yform aktiv ist.
 */
final class YformType extends AbstractType
{
    private const int MAX_CHOICES = 2000;

    public function key(): string
    {
        return 'yform';
    }

    public function label(): string
    {
        return I18n::t('fieldtype_yform');
    }

    public function icon(): string
    {
        return 'fa-database';
    }

    public function options(): array
    {
        $tables = ['' => I18n::t('please_choose')];
        foreach (self::tables() as $name => $label) {
            $tables[$name] = $label;
        }

        return [
            ['name' => 'table', 'label' => I18n::t('fieldopt_table'), 'type' => 'select', 'choices' => $tables],
            ['name' => 'label_field', 'label' => I18n::t('fieldopt_label_field'), 'type' => 'text', 'help' => I18n::t('fieldopt_label_field_help')],
            ['name' => 'multiple', 'label' => I18n::t('fieldopt_multiple'), 'type' => 'checkbox'],
            ['name' => 'allow_create', 'label' => I18n::t('fieldopt_allow_create'), 'type' => 'checkbox'],
        ];
    }

    public function render(FieldContext $context): string
    {
        $node = $context->node;
        $table = (string) $node->option('table', '');
        if (!isset(self::tables()[$table])) {
            return '<p class="text-warning">' . I18n::e('field_yform_missing') . '</p>';
        }

        $multiple = (bool) $node->option('multiple', false);
        $selected = array_map(strval(...), (array) ($context->value ?? []));
        $labelField = (string) $node->option('label_field', '');

        $select = sprintf(
            '<select class="form-control" id="%s" name="%s"%s%s>',
            $this->escape($context->inputId),
            $this->escape($context->inputName . ($multiple ? '[]' : '')),
            $multiple ? ' multiple size="6"' : '',
            $node->required ? ' required' : '',
        );
        if (!$multiple) {
            $select .= '<option value="">' . I18n::e('please_choose') . '</option>';
        }
        foreach (self::choicesFor($table, $labelField) as $id => $label) {
            $select .= sprintf('<option value="%d"%s>%s</option>', $id, in_array((string) $id, $selected, true) ? ' selected' : '', $this->escape($label));
        }
        $select .= '</select>';

        return sprintf(
            '<scheduler-yform-field table="%s" label-field="%s"%s>%s</scheduler-yform-field>',
            $this->escape($table),
            $this->escape($labelField),
            $node->option('allow_create', false)
                ? ' create-url="' . $this->escape(\rex_url::backendPage('yform/manager/data_edit', ['table_name' => $table, 'func' => 'add', 'rex_yform_manager_popup' => 1], false)) . '"'
                : '',
            $select,
        );
    }

    public function normalize(mixed $raw, FieldNode $node): mixed
    {
        $ids = array_values(array_unique(array_filter(array_map(static fn (mixed $v): int => is_scalar($v) ? (int) $v : 0, (array) $raw))));

        return $node->option('multiple', false) ? $ids : ($ids[0] ?? null);
    }

    public function validate(mixed $value, FieldNode $node): ?string
    {
        $table = (string) $node->option('table', '');
        if (!isset(self::tables()[$table])) {
            return null;
        }
        $ids = array_map(intval(...), (array) $value);
        $found = rex_sql::factory()->getArray(
            'SELECT id FROM ' . rex_sql::factory()->escapeIdentifier($table) . ' WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')',
            $ids,
        );

        return count($found) === count($ids) ? null : I18n::t('field_yform_gone');
    }

    public function isEmpty(mixed $value): bool
    {
        return null === $value || [] === $value || 0 === $value;
    }

    public function toText(mixed $value, FieldNode $node): string
    {
        $choices = self::choicesFor((string) $node->option('table', ''), (string) $node->option('label_field', ''));

        return implode(', ', array_map(static fn (mixed $id): string => $choices[(int) $id] ?? (string) $id, (array) $value));
    }

    public static function available(): bool
    {
        // Ohne laufendes REDAXO, etwa in Unit-Tests, gibt es den Typ nicht.
        return class_exists(rex_addon::class) && rex_addon::get('yform')->isAvailable() && class_exists(\rex_yform_manager_table::class);
    }

    /**
     * @return array<string, string> Tabellenname => Bezeichnung
     */
    public static function tables(): array
    {
        if (!self::available()) {
            return [];
        }
        $tables = [];
        foreach (\rex_yform_manager_table::getAll() as $table) {
            $tables[$table->getTableName()] = \rex_i18n::translate($table->getName()) . ' (' . $table->getTableName() . ')';
        }

        return $tables;
    }

    /**
     * @return array<int, string> ID => Anzeige, sortiert nach Anzeige
     */
    public static function choicesFor(string $table, string $labelField): array
    {
        if (!isset(self::tables()[$table])) {
            return [];
        }

        $sql = rex_sql::factory();
        $columns = array_column($sql->getArray('SHOW COLUMNS FROM ' . $sql->escapeIdentifier($table)), 'Type', 'Field');
        if (!isset($columns[$labelField])) {
            // Erste Textspalte; sonst dient die ID als Anzeige.
            $labelField = array_find_key($columns, static fn (string $type, string $name): bool => 'id' !== $name && 1 === preg_match('/char|text/i', $type)) ?? 'id';
        }

        $choices = [];
        $rows = $sql->getArray(sprintf(
            'SELECT `id`, %1$s AS `label` FROM %2$s ORDER BY %1$s LIMIT %3$d',
            $sql->escapeIdentifier($labelField),
            $sql->escapeIdentifier($table),
            self::MAX_CHOICES,
        ));
        foreach ($rows as $row) {
            $label = trim(strip_tags((string) $row['label']));
            $choices[(int) $row['id']] = '' !== $label ? $label : '#' . $row['id'];
        }

        return $choices;
    }
}
