<?php

declare(strict_types=1);

namespace KLXM\Scheduler\Field\Type;

use KLXM\Scheduler\I18n;
use KLXM\Scheduler\Backend\Pickers;
use KLXM\Scheduler\Field\FieldContext;
use KLXM\Scheduler\Field\FieldNode;

final class TimeType extends AbstractType
{
    private const string FORMAT = 'H:i';

    public function key(): string
    {
        return 'time';
    }

    public function label(): string
    {
        return I18n::t('fieldtype_time');
    }

    public function icon(): string
    {
        return 'fa-clock';
    }

    public function render(FieldContext $context): string
    {
        return Pickers::time($context->inputName, $context->stringValue(), ['id' => $context->inputId, 'required' => $context->node->required]);
    }

    public function validate(mixed $value, FieldNode $node): ?string
    {
        if ('' === $value) {
            return null;
        }
        $parsed = \DateTimeImmutable::createFromFormat('!' . self::FORMAT, (string) $value);

        return (false === $parsed || $parsed->format(self::FORMAT) !== $value) ? I18n::t('field_error_value') : null;
    }
}
