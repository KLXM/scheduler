<?php

declare(strict_types=1);

namespace KLXM\Scheduler\Field\Type;

use KLXM\Scheduler\I18n;
use KLXM\Scheduler\Field\FieldContext;
use KLXM\Scheduler\Field\FieldNode;

final class NumberType extends AbstractType
{
    public function key(): string
    {
        return 'number';
    }

    public function label(): string
    {
        return I18n::t('fieldtype_number');
    }

    public function icon(): string
    {
        return 'fa-hashtag';
    }

    public function options(): array
    {
        return [
            ['name' => 'min', 'label' => I18n::t('fieldopt_min'), 'type' => 'number'],
            ['name' => 'max', 'label' => I18n::t('fieldopt_number_max'), 'type' => 'number'],
            ['name' => 'step', 'label' => I18n::t('fieldopt_step'), 'type' => 'text', 'help' => I18n::t('fieldopt_step_help')],
        ];
    }

    public function render(FieldContext $context): string
    {
        $attributes = '';
        foreach (['min', 'max', 'step'] as $name) {
            if (null !== $context->node->option($name)) {
                $attributes .= sprintf(' %s="%s"', $name, $this->escape((string) $context->node->option($name)));
            }
        }

        return sprintf('<input class="form-control" type="number" inputmode="decimal" %s value="%s"%s>', $context->baseAttributes(), $this->escape($context->stringValue()), $attributes);
    }

    public function normalize(mixed $raw, FieldNode $node): mixed
    {
        $raw = is_scalar($raw) ? str_replace(',', '.', trim((string) $raw)) : '';
        if ('' === $raw || !is_numeric($raw)) {
            return '' === $raw ? null : $raw;
        }

        return str_contains($raw, '.') ? (float) $raw : (int) $raw;
    }

    public function validate(mixed $value, FieldNode $node): ?string
    {
        if (null === $value) {
            return null;
        }
        if (!is_int($value) && !is_float($value)) {
            return I18n::t('field_error_number');
        }
        if (null !== $node->option('min') && $value < (float) $node->option('min')) {
            return I18n::t('field_error_min', (string) $node->option('min'));
        }
        if (null !== $node->option('max') && $value > (float) $node->option('max')) {
            return I18n::t('field_error_max', (string) $node->option('max'));
        }

        return null;
    }

    public function isEmpty(mixed $value): bool
    {
        return null === $value || '' === $value;
    }
}
