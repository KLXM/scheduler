<?php

declare(strict_types=1);

namespace KLXM\Scheduler\Field\Type;

use KLXM\Scheduler\I18n;
use KLXM\Scheduler\Field\FieldContext;
use KLXM\Scheduler\Field\FieldNode;

final class CheckboxType extends AbstractType
{
    public function key(): string
    {
        return 'checkbox';
    }

    public function label(): string
    {
        return I18n::t('fieldtype_checkbox');
    }

    public function icon(): string
    {
        return 'fa-toggle-on';
    }

    public function options(): array
    {
        return [['name' => 'text', 'label' => I18n::t('fieldopt_text'), 'type' => 'text']];
    }

    public function render(FieldContext $context): string
    {
        return sprintf(
            '<label class="scheduler-switch"><input type="hidden" name="%2$s" value="0"><input type="checkbox" role="switch" id="%1$s" name="%2$s" value="1"%3$s> <span>%4$s</span></label>',
            $this->escape($context->inputId),
            $this->escape($context->inputName),
            $context->value ? ' checked' : '',
            $this->escape((string) $context->node->option('text', '')),
        );
    }

    public function normalize(mixed $raw, FieldNode $node): mixed
    {
        return in_array($raw, [1, '1', true, 'on'], true);
    }

    public function toText(mixed $value, FieldNode $node): string
    {
        return I18n::t($value ? 'yes' : 'no');
    }
}
