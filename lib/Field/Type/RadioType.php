<?php

declare(strict_types=1);

namespace KLXM\Scheduler\Field\Type;

use KLXM\Scheduler\I18n;
use KLXM\Scheduler\Field\FieldContext;
use KLXM\Scheduler\Field\FieldNode;

final class RadioType extends AbstractType
{
    public function key(): string
    {
        return 'radio';
    }

    public function label(): string
    {
        return I18n::t('fieldtype_radio');
    }

    public function icon(): string
    {
        return 'fa-circle-dot';
    }

    public function options(): array
    {
        return $this->choiceOptions();
    }

    public function render(FieldContext $context): string
    {
        $html = '<div class="scheduler-choice-group" role="radiogroup">';
        $index = 0;
        foreach ($this->choices($context->node) as $value => $label) {
            $html .= sprintf(
                '<label class="scheduler-choice"><input type="radio" id="%s-%d" name="%s" value="%s"%s%s> <span>%s</span></label>',
                $this->escape($context->inputId),
                $index++,
                $this->escape($context->inputName),
                $this->escape((string) $value),
                (string) $value === $context->stringValue() ? ' checked' : '',
                $context->node->required ? ' required' : '',
                $this->escape($label),
            );
        }

        return $html . '</div>';
    }

    public function validate(mixed $value, FieldNode $node): ?string
    {
        return ('' !== $value && !array_key_exists((string) $value, $this->choices($node))) ? I18n::t('field_error_choice') : null;
    }

    public function toText(mixed $value, FieldNode $node): string
    {
        return $this->choices($node)[(string) $value] ?? (string) $value;
    }
}
