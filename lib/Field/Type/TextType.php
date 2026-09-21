<?php

declare(strict_types=1);

namespace KLXM\Scheduler\Field\Type;

use KLXM\Scheduler\I18n;
use KLXM\Scheduler\Field\FieldContext;
use KLXM\Scheduler\Field\FieldNode;

final class TextType extends AbstractType
{
    public function key(): string
    {
        return 'text';
    }

    public function label(): string
    {
        return I18n::t('fieldtype_text');
    }

    public function icon(): string
    {
        return 'fa-font';
    }

    public function options(): array
    {
        return [
            ['name' => 'input', 'label' => I18n::t('fieldopt_input'), 'type' => 'select', 'choices' => ['text' => I18n::t('fieldtype_text'), 'email' => I18n::t('field_input_email'), 'url' => 'URL', 'tel' => I18n::t('field_input_tel')]],
            ['name' => 'placeholder', 'label' => I18n::t('fieldopt_placeholder'), 'type' => 'text'],
            ['name' => 'maxlength', 'label' => I18n::t('fieldopt_maxlength'), 'type' => 'number'],
        ];
    }

    public function render(FieldContext $context): string
    {
        $node = $context->node;
        $maxlength = (int) $node->option('maxlength', 0);

        return sprintf(
            '<input class="form-control" type="%s" %s value="%s"%s%s>',
            $this->escape((string) $node->option('input', 'text')),
            $context->baseAttributes(),
            $this->escape($context->stringValue()),
            $maxlength > 0 ? ' maxlength="' . $maxlength . '"' : '',
            null !== $node->option('placeholder') ? ' placeholder="' . $this->escape((string) $node->option('placeholder')) . '"' : '',
        );
    }

    public function validate(mixed $value, FieldNode $node): ?string
    {
        if ('' === $value) {
            return null;
        }

        return match ($node->option('input', 'text')) {
            'email' => false === filter_var($value, FILTER_VALIDATE_EMAIL) ? I18n::t('field_error_email') : null,
            'url' => false === filter_var($value, FILTER_VALIDATE_URL) ? I18n::t('field_error_url') : null,
            default => null,
        };
    }
}
