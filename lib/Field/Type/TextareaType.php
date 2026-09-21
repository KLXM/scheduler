<?php

declare(strict_types=1);

namespace KLXM\Scheduler\Field\Type;

use KLXM\Scheduler\I18n;
use KLXM\Scheduler\Field\FieldContext;
use KLXM\Scheduler\Field\FieldNode;

final class TextareaType extends AbstractType
{
    public function key(): string
    {
        return 'textarea';
    }

    public function label(): string
    {
        return I18n::t('fieldtype_textarea');
    }

    public function icon(): string
    {
        return 'fa-align-left';
    }

    public function options(): array
    {
        return [
            ['name' => 'rows', 'label' => I18n::t('fieldopt_rows'), 'type' => 'number'],
            ['name' => 'editor_class', 'label' => I18n::t('fieldopt_editor_class'), 'type' => 'text', 'help' => I18n::t('fieldopt_editor_class_help')],
            ['name' => 'editor_profile', 'label' => I18n::t('fieldopt_editor_profile'), 'type' => 'text'],
        ];
    }

    public function render(FieldContext $context): string
    {
        $node = $context->node;
        $class = trim('form-control ' . (string) $node->option('editor_class', ''));
        $profile = (string) $node->option('editor_profile', '');

        return sprintf(
            '<textarea class="%s" rows="%d" %s%s>%s</textarea>',
            $this->escape($class),
            max(2, (int) $node->option('rows', 5)),
            $context->baseAttributes(),
            '' !== $profile ? ' data-profile="' . $this->escape($profile) . '" data-lang="' . $this->escape(\rex_i18n::getLanguage()) . '"' : '',
            $this->escape($context->stringValue()),
        );
    }

    public function normalize(mixed $raw, FieldNode $node): mixed
    {
        return is_scalar($raw) ? (string) $raw : '';
    }

    public function toText(mixed $value, FieldNode $node): string
    {
        return trim(html_entity_decode(strip_tags((string) (is_scalar($value) ? $value : '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}
