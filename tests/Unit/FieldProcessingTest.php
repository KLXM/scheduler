<?php

declare(strict_types=1);

namespace KLXM\Scheduler\Tests\Unit;

use KLXM\Scheduler\Field\Schema;
use KLXM\Scheduler\Field\TypeRegistry;
use KLXM\Scheduler\Field\ValueProcessor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FieldProcessingTest extends TestCase
{
    private function schema(): Schema
    {
        return Schema::fromArray(['nodes' => [
            ['type' => 'tab', 'label' => 'Anmeldung', 'children' => [
                ['type' => 'checkbox', 'name' => 'signup', 'label' => 'Anmeldung nötig'],
                ['type' => 'text', 'name' => 'signup_url', 'label' => 'Link', 'required' => true, 'options' => ['input' => 'url'], 'visibleIf' => ['field' => 'signup', 'operator' => '=', 'value' => '1']],
                ['type' => 'number', 'name' => 'seats', 'label' => 'Plätze', 'options' => ['min' => 1]],
            ]],
            ['type' => 'tab', 'label' => 'Inhalt', 'children' => [
                ['type' => 'textarea', 'name' => 'caption', 'label' => 'Bildtext', 'translatable' => true, 'required' => true],
                ['type' => 'select', 'name' => 'level', 'options' => ['choices' => "a|Anfänger\nb|Profis"]],
                ['type' => 'repeater', 'name' => 'speakers', 'options' => ['max' => 2], 'children' => [
                    ['type' => 'text', 'name' => 'name', 'required' => true],
                    ['type' => 'text', 'name' => 'role'],
                ]],
            ]],
        ]]);
    }

    #[Test]
    public function normalizesValidatesAndDropsUnknownKeys(): void
    {
        $result = new ValueProcessor(TypeRegistry::withDefaults())->process(
            $this->schema(),
            ['signup' => '1', 'signup_url' => 'https://example.org/go', 'seats' => '12', 'level' => 'b', 'evil' => 'x',
                'speakers' => [['name' => ' Ada ', 'role' => ''], ['name' => '', 'role' => ''], ['name' => 'Bob'], ['name' => 'Zu viel']]],
            [1 => ['caption' => 'Hallo'], 2 => ['caption' => '']],
            [1, 2],
            ['_legacy_id' => 7, 'gone' => 'old'],
        );

        self::assertSame([], $result->errors);
        self::assertSame(
            ['_legacy_id' => 7, 'gone' => 'old', 'signup' => true, 'signup_url' => 'https://example.org/go', 'seats' => 12, 'level' => 'b', 'speakers' => [['name' => 'Ada'], ['name' => 'Bob']]],
            $result->values,
        );
        self::assertSame([1 => ['caption' => 'Hallo']], $result->translatedValues);
    }

    #[Test]
    public function reportsErrorsButSkipsHiddenRequiredFields(): void
    {
        $result = new ValueProcessor(TypeRegistry::withDefaults())->process(
            $this->schema(),
            ['signup' => '0', 'signup_url' => '', 'seats' => '0', 'level' => 'zzz'],
            [],
            [1, 2],
        );

        self::assertArrayNotHasKey('signup_url', $result->errors, 'Ausgeblendetes Pflichtfeld wird nicht geprüft');
        self::assertArrayHasKey('seats', $result->errors);
        self::assertArrayHasKey('level', $result->errors);
        self::assertArrayHasKey('caption', $result->errors, 'Übersetzbares Pflichtfeld gilt in der ersten Sprache');
    }

    #[Test]
    public function schemaValidationCatchesBadNamesDuplicatesAndReservedNames(): void
    {
        $schema = Schema::fromArray(['nodes' => [
            ['type' => 'text', 'name' => 'Bad Name'],
            ['type' => 'text', 'name' => 'room'],
            ['type' => 'fieldset', 'children' => [['type' => 'text', 'name' => 'room']]],
            ['type' => 'text', 'name' => 'rrule'],
            ['type' => 'hologram', 'name' => 'future'],
            ['type' => 'repeater', 'name' => 'rows', 'children' => [['type' => 'media', 'name' => 'img']]],
        ]]);

        $errors = implode("\n", $schema->validate(TypeRegistry::withDefaults(), ['rrule', 'uid']));

        self::assertStringContainsString('Bad Name', $errors);
        self::assertStringContainsString('„room“ ist doppelt', $errors);
        self::assertStringContainsString('reserviert', $errors);
        self::assertStringContainsString('hologram', $errors);
        self::assertStringContainsString('in Wiederholungen nicht erlaubt', $errors);
    }

    #[Test]
    public function schemaSurvivesArrayRoundTrip(): void
    {
        $schema = $this->schema();

        self::assertSame($schema->toArray(), Schema::fromArray($schema->toArray())->toArray());
        self::assertSame(['signup', 'signup_url', 'seats', 'caption', 'level', 'speakers'], array_keys($schema->fields()));
    }
}
