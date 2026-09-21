<?php

declare(strict_types=1);

namespace KLXM\Scheduler\Field\Type;

use KLXM\Scheduler\I18n;
use KLXM\Scheduler\Field\FieldContext;
use KLXM\Scheduler\Field\FieldNode;

final class ColorType extends AbstractType
{
    public function key(): string
    {
        return 'color';
    }

    public function label(): string
    {
        return I18n::t('fieldtype_color');
    }

    public function icon(): string
    {
        return 'fa-palette';
    }

    public function render(FieldContext $context): string
    {
        $value = '' !== $context->stringValue() ? $context->stringValue() : '#3788d8';

        return sprintf('<input class="scheduler-color" type="color" %s value="%s">', $context->baseAttributes(), $this->escape($value));
    }

    public function validate(mixed $value, FieldNode $node): ?string
    {
        return ('' !== $value && 1 !== preg_match('/^#[0-9a-fA-F]{6}$/', (string) $value)) ? I18n::t('field_error_color') : null;
    }
}
