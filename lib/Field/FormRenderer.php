<?php

declare(strict_types=1);

namespace KLXM\Scheduler\Field;

use KLXM\Scheduler\I18n;
/**
 * Rendert ein Schema als Formularabschnitt. Feldnamen folgen dem Muster custom[feld] und
 * custom_lang[sprache][feld]; der ValueProcessor liest sie wieder ein.
 */
final class FormRenderer
{
    private int $sequence = 0;

    /**
     * @param array<int, string> $clangs Sprach-ID => Name, Start-Sprache zuerst
     */
    public function __construct(
        private readonly TypeRegistry $types,
        private readonly array $clangs,
        private readonly string $prefix = 'custom',
    ) {}

    /**
     * @param array<string, mixed> $values
     * @param array<int, array<string, mixed>> $translatedValues nach Sprach-ID
     * @param array<string, string> $errors Feldname => Meldung
     */
    public function render(Schema $schema, array $values, array $translatedValues = [], array $errors = []): string
    {
        if ($schema->isEmpty) {
            return '';
        }

        return '<scheduler-form class="scheduler-custom-fields">' . $this->nodes($schema->nodes, $values, $translatedValues, $errors) . '</scheduler-form>';
    }

    /**
     * @param list<FieldNode> $nodes
     * @param array<string, mixed> $values
     * @param array<int, array<string, mixed>> $translatedValues
     * @param array<string, string> $errors
     */
    private function nodes(array $nodes, array $values, array $translatedValues, array $errors): string
    {
        $html = '';
        $tabs = [];
        foreach ($nodes as $node) {
            if ('tab' === $node->type) {
                $tabs[] = $node;
                continue;
            }
            $html .= $this->flushTabs($tabs, $values, $translatedValues, $errors) . $this->node($node, $values, $translatedValues, $errors);
        }

        return $html . $this->flushTabs($tabs, $values, $translatedValues, $errors);
    }

    /**
     * Aufeinanderfolgende Tab-Knoten bilden gemeinsam eine Tab-Leiste.
     *
     * @param list<FieldNode> $tabs
     * @param array<string, mixed> $values
     * @param array<int, array<string, mixed>> $translatedValues
     * @param array<string, string> $errors
     */
    private function flushTabs(array &$tabs, array $values, array $translatedValues, array $errors): string
    {
        if ([] === $tabs) {
            return '';
        }

        $html = '<scheduler-tabs>';
        foreach ($tabs as $tab) {
            $html .= sprintf(
                '<section data-tab-label="%s"%s>%s</section>',
                $this->escape('' !== $tab->label ? $tab->label : I18n::t('fb_tab')),
                $this->visibility($tab),
                $this->nodes($tab->children, $values, $translatedValues, $errors),
            );
        }
        $tabs = [];

        return $html . '</scheduler-tabs>';
    }

    /**
     * @param array<string, mixed> $values
     * @param array<int, array<string, mixed>> $translatedValues
     * @param array<string, string> $errors
     */
    private function node(FieldNode $node, array $values, array $translatedValues, array $errors): string
    {
        return match ($node->type) {
            'fieldset' => sprintf(
                '<fieldset class="scheduler-fieldset"%s>%s%s</fieldset>',
                $this->visibility($node),
                '' !== $node->label ? '<legend>' . $this->escape($node->label) . '</legend>' : '',
                $this->nodes($node->children, $values, $translatedValues, $errors),
            ),
            'columns' => '<div class="scheduler-columns"' . $this->visibility($node) . '>' . $this->nodes($node->children, $values, $translatedValues, $errors) . '</div>',
            'column' => sprintf(
                '<div class="scheduler-column" style="--scheduler-span:%d">%s</div>',
                min(12, max(1, (int) $node->option('width', 6))),
                $this->nodes($node->children, $values, $translatedValues, $errors),
            ),
            'repeater' => $this->repeater($node, $values, $errors),
            default => $this->field($node, $values, $translatedValues, $errors),
        };
    }

    /**
     * @param array<string, mixed> $values
     * @param array<int, array<string, mixed>> $translatedValues
     * @param array<string, string> $errors
     */
    private function field(FieldNode $node, array $values, array $translatedValues, array $errors): string
    {
        if (!$this->types->has($node->type)) {
            return '';
        }
        $type = $this->types->get($node->type);

        if ($node->translatable && count($this->clangs) > 1) {
            $input = '<scheduler-lang-field>';
            foreach ($this->clangs as $clangId => $clangName) {
                $context = new FieldContext(
                    // Pflicht gilt nur in der ersten Sprache, die übrigen fallen auf sie zurück.
                    $clangId === array_key_first($this->clangs) ? $node : $this->optional($node),
                    sprintf('%s_lang[%d][%s]', $this->prefix, $clangId, $node->name),
                    $this->id($node->name . '-' . $clangId),
                    $translatedValues[$clangId][$node->name] ?? null,
                );
                $input .= sprintf('<div data-lang="%s" data-lang-id="%d">%s</div>', $this->escape($clangName), $clangId, $type->render($context));
            }
            $input .= '</scheduler-lang-field>';
            $inputId = $this->prefix . '-' . $node->name . '-' . array_key_first($this->clangs);
        } else {
            $isTranslatable = $node->translatable;
            $clangId = array_key_first($this->clangs) ?? 1;
            $context = new FieldContext(
                $node,
                $isTranslatable ? sprintf('%s_lang[%d][%s]', $this->prefix, $clangId, $node->name) : sprintf('%s[%s]', $this->prefix, $node->name),
                $inputId = $this->id($node->name),
                $isTranslatable ? ($translatedValues[$clangId][$node->name] ?? null) : ($values[$node->name] ?? null),
            );
            $input = $type->render($context);
        }

        return $this->wrap($node, $inputId, $input, $errors[$node->name] ?? null);
    }

    /**
     * @param array<string, mixed> $values
     * @param array<string, string> $errors
     */
    private function repeater(FieldNode $node, array $values, array $errors): string
    {
        $rows = array_values(array_filter((array) ($values[$node->name] ?? []), is_array(...)));
        $row = function (string $index, array $rowValues) use ($node): string {
            $html = '';
            foreach ($node->children as $child) {
                if (!$child->isField() || !$this->types->has($child->type)) {
                    continue;
                }
                $context = new FieldContext(
                    $child,
                    sprintf('%s[%s][%s][%s]', $this->prefix, $node->name, $index, $child->name),
                    $this->id($node->name . '-' . $index . '-' . $child->name),
                    $rowValues[$child->name] ?? null,
                );
                $html .= $this->wrap($child, $context->inputId, $this->types->get($child->type)->render($context), null);
            }

            return '<div class="scheduler-repeater-row" data-row><div class="scheduler-repeater-fields">' . $html . '</div>'
                . '<div class="scheduler-repeater-actions">'
                . '<button type="button" class="btn btn-default btn-xs" data-action="up" title="' . I18n::e('move_up') . '" aria-label="' . I18n::e('move_up') . '"><i class="rex-icon fa-arrow-up"></i></button>'
                . '<button type="button" class="btn btn-default btn-xs" data-action="down" title="' . I18n::e('move_down') . '" aria-label="' . I18n::e('move_down') . '"><i class="rex-icon fa-arrow-down"></i></button>'
                . '<button type="button" class="btn btn-delete btn-xs" data-action="remove" title="' . I18n::e('remove') . '" aria-label="' . I18n::e('remove') . '"><i class="rex-icon fa-trash"></i></button>'
                . '</div></div>';
        };

        $html = sprintf(
            '<scheduler-repeater class="scheduler-repeater" data-field="%s" min="%d" max="%d"%s>',
            $this->escape($node->name),
            max(0, (int) $node->option('min', 0)),
            max(0, (int) $node->option('max', 0)),
            $this->visibility($node),
        );
        $html .= '<div class="scheduler-repeater-head"><span class="control-label">' . $this->escape('' !== $node->label ? $node->label : $node->name) . '</span></div>';
        // Leeres Feld, damit ein vollständig geleerter Repeater auch als leer ankommt.
        $html .= sprintf('<input type="hidden" name="%s[%s]" value="">', $this->prefix, $this->escape($node->name));
        $html .= '<div data-rows>';
        foreach ($rows as $index => $rowValues) {
            $html .= $row((string) $index, $rowValues);
        }
        $html .= '</div><template>' . $row('__index__', []) . '</template>';
        $html .= '<button type="button" class="btn btn-default btn-sm" data-action="add"><i class="rex-icon fa-plus"></i> ' . $this->escape((string) $node->option('add_label', I18n::t('repeater_add'))) . '</button>';
        if (isset($errors[$node->name])) {
            $html .= '<p class="scheduler-error" role="alert">' . $this->escape($errors[$node->name]) . '</p>';
        }

        return $html . '</scheduler-repeater>';
    }

    private function wrap(FieldNode $node, string $inputId, string $input, ?string $error): string
    {
        return sprintf(
            '<div class="form-group scheduler-field%s" data-field="%s"%s><label class="control-label" for="%s">%s%s</label><div class="scheduler-field-input">%s%s%s</div></div>',
            null !== $error ? ' has-error' : '',
            $this->escape($node->name),
            $this->visibility($node),
            $this->escape($inputId),
            $this->escape('' !== $node->label ? $node->label : $node->name),
            $node->required ? ' <abbr class="scheduler-required" title="' . I18n::e('required_field') . '">*</abbr>' : '',
            $input,
            '' !== $node->help ? '<p class="help-block">' . $this->escape($node->help) . '</p>' : '',
            null !== $error ? '<p class="scheduler-error" role="alert">' . $this->escape($error) . '</p>' : '',
        );
    }

    private function visibility(FieldNode $node): string
    {
        return null === $node->visibleIf
            ? ''
            : ' data-visible-if="' . $this->escape(json_encode($node->visibleIf, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)) . '"';
    }

    private function optional(FieldNode $node): FieldNode
    {
        return FieldNode::fromArray([...$node->toArray(), 'required' => false]);
    }

    private function id(string $name): string
    {
        // Die laufende Nummer hält IDs eindeutig, wenn mehrere Formulare auf einer Seite stehen.
        return sprintf('%s-%s-%d', $this->prefix, preg_replace('/[^a-zA-Z0-9_-]/', '-', $name), ++$this->sequence);
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
