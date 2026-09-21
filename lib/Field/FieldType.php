<?php

declare(strict_types=1);

namespace KLXM\Scheduler\Field;

/**
 * Ein Feldtyp des Formbuilders. Eigene Typen registriert man über TypeRegistry::register().
 */
interface FieldType
{
    /** Technischer Schlüssel im Schema, etwa "text". */
    public function key(): string;

    public function label(): string;

    /** Font-Awesome-Klasse für die Palette des Builders. */
    public function icon(): string;

    /**
     * Zusätzliche Einstellungen im Eigenschaften-Panel des Builders.
     *
     * @return list<array{name: string, label: string, type: 'text'|'number'|'textarea'|'checkbox'|'select', choices?: array<string, string>, help?: string}>
     */
    public function options(): array;

    /** Darf der Typ innerhalb eines Repeaters verwendet werden? */
    public function allowedInRepeater(): bool;

    /** Eingabeelement ohne Label und Hilfetext. */
    public function render(FieldContext $context): string;

    /** Bringt den rohen Request-Wert in die gespeicherte Form. */
    public function normalize(mixed $raw, FieldNode $node): mixed;

    /** @return string|null Fehlermeldung oder null */
    public function validate(mixed $value, FieldNode $node): ?string;

    public function isEmpty(mixed $value): bool;

    /** Wert für die iCalendar-Ausgabe als X-Eigenschaft. */
    public function toText(mixed $value, FieldNode $node): string;
}
