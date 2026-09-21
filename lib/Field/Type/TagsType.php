<?php

declare(strict_types=1);

namespace KLXM\Scheduler\Field\Type;

use KLXM\Scheduler\I18n;
use KLXM\Scheduler\Field\FieldContext;
use KLXM\Scheduler\Field\FieldNode;

final class TagsType extends AbstractType
{
    public function key(): string
    {
        return 'tags';
    }

    public function label(): string
    {
        return I18n::t('fieldtype_tags');
    }

    public function icon(): string
    {
        return 'fa-tags';
    }

    public function options(): array
    {
        return [['name' => 'max', 'label' => I18n::t('fieldopt_tags_max'), 'type' => 'number']];
    }

    public function render(FieldContext $context): string
    {
        $tags = array_filter((array) ($context->value ?? []), is_scalar(...));

        return sprintf(
            '<scheduler-tags name="%s" input-id="%s" max="%d" value="%s"></scheduler-tags>',
            $this->escape($context->inputName),
            $this->escape($context->inputId),
            (int) $context->node->option('max', 0),
            $this->escape(json_encode(array_values(array_map(strval(...), $tags)), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)),
        );
    }

    public function normalize(mixed $raw, FieldNode $node): mixed
    {
        if (is_string($raw)) {
            $raw = json_validate($raw) ? json_decode($raw, true) : explode(',', $raw);
        }
        $tags = array_values(array_unique(array_filter(array_map(static fn ($t): string => is_scalar($t) ? trim((string) $t) : '', (array) $raw), static fn (string $t): bool => '' !== $t)));
        $max = (int) $node->option('max', 0);

        return $max > 0 ? array_slice($tags, 0, $max) : $tags;
    }
}
