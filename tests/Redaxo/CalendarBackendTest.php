<?php

declare(strict_types=1);

namespace KLXM\Scheduler\Tests\Redaxo;

use KLXM\Scheduler\Dav\CalendarBackend;
use KLXM\Dav\Context;
use KLXM\Dav\Scope;
use KLXM\Dav\Token;
use KLXM\Scheduler\Scheduler;
use PHPUnit\Framework\Attributes\Test;
use Sabre\DAV\Exception\Forbidden;

final class CalendarBackendTest extends RedaxoTestCase
{
    private const string ICS = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:dav-test-%s\r\nDTSTART;TZID=Europe/Berlin:20261110T090000\r\nDTEND;TZID=Europe/Berlin:20261110T100000\r\nRRULE:FREQ=WEEKLY;COUNT=3\r\nSUMMARY:%s\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";

    protected function setUp(): void
    {
        parent::setUp();
        if (!\rex_addon::get('dav')->isAvailable()) {
            self::markTestSkipped('Braucht das Addon dav.');
        }
    }

    private function backend(Scope $scope): CalendarBackend
    {
        $context = new Context();
        $context->user = \rex::requireUser();
        $context->token = new Token(1, $context->user->getId(), 'phpunit', $scope, null, null, null);

        return new CalendarBackend($context, \rex_clang::getStartId());
    }

    #[Test]
    public function clientCanCreateUpdateSyncAndDeleteEvents(): void
    {
        $backend = $this->backend(Scope::ReadWrite);
        $calendarId = (int) $this->calendar->id;
        $suffix = bin2hex(random_bytes(4));
        $startToken = (string) Scheduler::calendars()->findOrFail($calendarId)->syncToken;

        $backend->createCalendarObject($calendarId, 'a.ics', sprintf(self::ICS, $suffix, 'Vom Client'));
        $object = $backend->getCalendarObject($calendarId, 'a.ics');
        self::assertNotNull($object);
        self::assertStringContainsString('SUMMARY:Vom Client', $object['calendardata']);
        self::assertStringContainsString('BEGIN:VTIMEZONE', $object['calendardata']);
        self::assertSame(3, Scheduler::occurrences()->includeUnpublished()->inCalendars($calendarId)->between('2026-11-01', '2026-12-31')->count());

        $backend->updateCalendarObject($calendarId, 'a.ics', sprintf(self::ICS, $suffix, 'Geändert'));
        self::assertNotSame($object['etag'], $backend->getCalendarObject($calendarId, 'a.ics')['etag'] ?? null);

        $changes = $backend->getChangesForCalendar($calendarId, $startToken, 1);
        self::assertSame(['a.ics'], $changes['added'] ?? null, 'Angelegt und geändert zählt für den Client als neu');

        $filter = ['name' => 'VCALENDAR', 'comp-filters' => [['name' => 'VEVENT', 'comp-filters' => [], 'prop-filters' => [], 'is-not-defined' => false,
            'time-range' => ['start' => new \DateTimeImmutable('2026-11-16'), 'end' => new \DateTimeImmutable('2026-11-18')]]], 'prop-filters' => [], 'is-not-defined' => false, 'time-range' => null];
        self::assertSame(['a.ics'], $backend->calendarQuery($calendarId, $filter));

        $afterCreate = $changes['syncToken'];
        $backend->deleteCalendarObject($calendarId, 'a.ics');
        self::assertNull($backend->getCalendarObject($calendarId, 'a.ics'));
        self::assertSame(['a.ics'], $backend->getChangesForCalendar($calendarId, $afterCreate, 1)['deleted'] ?? null);
    }

    #[Test]
    public function readOnlyTokenCannotWrite(): void
    {
        $this->expectException(Forbidden::class);
        $this->backend(Scope::Read)->createCalendarObject((int) $this->calendar->id, 'b.ics', sprintf(self::ICS, 'ro', 'Nein'));
    }
}
