<?php

declare(strict_types=1);

namespace KLXM\Scheduler\Field;

/**
 * Alles, was ein Feldtyp zum Rendern eines Eingabeelements braucht.
 */
final readonly class FieldContext
{
    public function __construct(
        public FieldNode $node,
        /** Vollständiger Name des Formularfelds, etwa custom[room] */
        public string $inputName,
        /** Eindeutige, HTML-taugliche ID */
        public string $inputId,
        public mixed $value,
    ) {}

    /** Attribute, die jedes Eingabeelement tragen sollte. */
    public function baseAttributes(): string
    {
        return sprintf(
            'id="%s" name="%s"%s',
            htmlspecialchars($this->inputId, ENT_QUOTES),
            htmlspecialchars($this->inputName, ENT_QUOTES),
            $this->node->required ? ' required' : '',
        );
    }

    public function stringValue(): string
    {
        return is_scalar($this->value) ? (string) $this->value : '';
    }
}
