<?php

declare(strict_types=1);

namespace KLXM\Scheduler\Field\Type;

use KLXM\Scheduler\I18n;
use KLXM\Scheduler\Field\FieldContext;
use KLXM\Scheduler\Field\FieldNode;

final class SelectType extends AbstractType
{
    public function key(): string
    {
        return 'select';
    }

    public function label(): string
    {
        return I18n::t('fieldtype_select');
    }

    public function icon(): string
    {
        return 'fa-list';
    }

    public function options(): array
    {
        return [...$this->choiceOptions(), ['name' => 'multiple', 'label' => I18n::t('fieldopt_multiple'), 'type' => 'checkbox']];
    }

    public function render(FieldContext $context): string
    {
        $multiple = (bool) $context->node->option('multiple', false);
        $selected = array_map(strval(...), (array) ($context->value ?? []));

        $html = sprintf(
            '<select class="form-control" id="%s" name="%s"%s%s>',
            $this->escape($context->inputId),
            $this->escape($context->inputName . ($multiple ? '[]' : '')),
            $multiple ? ' multiple size="6"' : '',
            $context->node->required ? ' required' : '',
        );
        if (!$multiple) {
            $html .= '<option value="">' . I18n::e('please_choose') . '</option>';
        }
        foreach ($this->choices($context->node) as $value => $label) {
            $html .= sprintf('<option value="%s"%s>%s</option>', $this->escape((string) $value), in_array((string) $value, $selected, true) ? ' selected' : '', $this->escape($label));
        }

        return $html . '</select>';
    }

    public function normalize(mixed $raw, FieldNode $node): mixed
    {
        if ($node->option('multiple', false)) {
            return array_values(array_filter(array_map(static fn ($v): string => is_scalar($v) ? trim((string) $v) : '', (array) $raw), static fn (string $v): bool => '' !== $v));
        }

        return is_scalar($raw) ? trim((string) $raw) : '';
    }

    public function validate(mixed $value, FieldNode $node): ?string
    {
        $choices = $this->choices($node);
        foreach ((array) $value as $single) {
            if ('' !== $single && !array_key_exists((string) $single, $choices)) {
                return I18n::t('field_error_choice');
            }
        }

        return null;
    }

    public function toText(mixed $value, FieldNode $node): string
    {
        $choices = $this->choices($node);

        return implode(', ', array_map(static fn ($v): string => $choices[(string) $v] ?? (string) $v, (array) $value));
    }
}
