<?php

declare(strict_types=1);

namespace KLXM\Scheduler\Tests\Redaxo;

use DateTimeImmutable;
use KLXM\Scheduler\Domain\Enum\SchemaTarget;
use KLXM\Scheduler\Domain\Event;
use KLXM\Scheduler\Domain\FieldSchema;
use KLXM\Scheduler\Field\Schema;
use KLXM\Scheduler\Scheduler;
use KLXM\Scheduler\Service\ValidationException;
use PHPUnit\Framework\Attributes\Test;

final class SchemaServiceTest extends RedaxoTestCase
{
    /** @var array<string, mixed> */
    private array $originalDefinition = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalDefinition = Scheduler::schemas()->active(SchemaTarget::Event)->toArray();
    }

    protected function tearDown(): void
    {
        // Ursprüngliches Schema wiederherstellen und die Testversionen entfernen.
        Scheduler::schemas()->publish(SchemaTarget::Event, Schema::fromArray($this->originalDefinition), note: 'phpunit-restore');
        Scheduler::em()->repository(FieldSchema::class)->query()->whereRaw('{note} LIKE ?', ['phpunit%'])->where('active', false)->delete();
        parent::tearDown();
    }

    #[Test]
    public function publishesVersionsRenamesValuesAndMaintainsIndexColumn(): void
    {
        $service = Scheduler::schemas();
        $service->publish(SchemaTarget::Event, Schema::fromArray(['nodes' => [
            ['type' => 'text', 'name' => 'zz_room', 'label' => 'Raum', 'filterable' => true],
        ]]), note: 'phpunit-1');

        $event = new Event(new DateTimeImmutable('2026-08-01 10:00'), new DateTimeImmutable('2026-08-01 11:00'));
        $event->calendarId = (int) $this->calendar->id;
        $event->translate(1)->title = 'Schema-Test';
        $event->custom = ['zz_room' => 'Aula'];
        Scheduler::events()->save($event);

        self::assertSame('cf_zz_room', $service->indexColumn(SchemaTarget::Event, 'zz_room'));
        self::assertSame(1, $service->usage(SchemaTarget::Event, 'zz_room'));
        $query = Scheduler::occurrences()->inCalendars((int) $this->calendar->id)->between('2026-08-01', '2026-08-02');
        self::assertSame(1, (clone $query)->whereCustom('zz_room', 'Aula')->count(), 'Filter über die Indexspalte');

        $service->publish(SchemaTarget::Event, Schema::fromArray(['nodes' => [
            ['type' => 'text', 'name' => 'zz_place', 'label' => 'Raum'],
        ]]), ['zz_room' => 'zz_place'], 'phpunit-2');

        $reloaded = Scheduler::events()->findOrFail((int) $event->id);
        self::assertSame('Aula', $reloaded->custom('zz_place'));
        self::assertNull($reloaded->custom('zz_room'));
        self::assertNull($service->indexColumn(SchemaTarget::Event, 'zz_place'));
        self::assertSame(1, (clone $query)->whereCustom('zz_place', 'Aula')->count(), 'Filter über JSON ohne Indexspalte');
        self::assertSame([], Scheduler::em()->connection->fetchAll("SHOW COLUMNS FROM " . Scheduler::em()->connection->table('scheduler_event') . " LIKE 'cf\\_zz%'"));
    }

    #[Test]
    public function rejectsSchemaThatShadowsCoreField(): void
    {
        $this->expectException(ValidationException::class);
        Scheduler::schemas()->publish(SchemaTarget::Event, Schema::fromArray(['nodes' => [['type' => 'text', 'name' => 'rrule']]]), note: 'phpunit-bad');
    }

    #[Test]
    public function exportAndImportAreIdempotent(): void
    {
        $service = Scheduler::schemas();
        $service->publish(SchemaTarget::Event, Schema::fromArray(['nodes' => [['type' => 'number', 'name' => 'zz_seats']]]), note: 'phpunit-3');
        $json = $service->exportJson(SchemaTarget::Event);

        self::assertFalse($service->importJson($json, 'phpunit-import'), 'Unverändertes Schema erzeugt keine neue Version');
        self::assertTrue($service->importJson(str_replace('zz_seats', 'zz_places', $json), 'phpunit-import'));
        self::assertArrayHasKey('zz_places', $service->active(SchemaTarget::Event)->fields());
    }
}
