<?php

declare(strict_types=1);

namespace KLXM\Scheduler\Field\Type;

use KLXM\Scheduler\I18n;
use KLXM\Scheduler\Field\FieldContext;
use KLXM\Scheduler\Field\FieldNode;

/**
 * Nutzt das REDAXO-Widget. Die Widgets arbeiten mit festen Element-IDs und sind deshalb
 * in Wiederholungen nicht erlaubt.
 */
final class LinkListType extends AbstractType
{
    public function key(): string
    {
        return 'linklist';
    }

    public function label(): string
    {
        return I18n::t('fieldtype_linklist');
    }

    public function icon(): string
    {
        return 'fa-list-check';
    }

    public function allowedInRepeater(): bool
    {
        return false;
    }

    public function options(): array
    {
        return [
            ['name' => 'category', 'label' => I18n::t('fieldopt_linklist_category'), 'type' => 'number'],
        ];
    }

    public function render(FieldContext $context): string
    {
        if (!class_exists(\rex_var_linklist::class)) {
            return '<p class="text-warning">' . I18n::e('field_widget_missing') . '</p>';
        }

        $args = array_filter([
            'types' => $context->node->option('types'),
            'category' => $context->node->option('category'),
            'preview' => $context->node->option('preview') ? 1 : null,
        ], static fn (mixed $v): bool => null !== $v);

        // Das Widget erzeugt sein Eingabefeld selbst; die ID muss nur eindeutig sein.
        return \rex_var_linklist::getWidget($context->inputId, $context->inputName, $context->stringValue(), $args);
    }

    public function normalize(mixed $raw, FieldNode $node): mixed
    {
        $value = is_scalar($raw) ? trim((string) $raw) : '';

        return '' === $value ? [] : array_values(array_filter(array_map(trim(...), explode(',', $value))));
    }

    public function isEmpty(mixed $value): bool
    {
        return null === $value || '' === $value || [] === $value;
    }
}
