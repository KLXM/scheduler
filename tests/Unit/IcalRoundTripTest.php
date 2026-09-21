<?php

declare(strict_types=1);

namespace KLXM\Scheduler\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use KLXM\Scheduler\Domain\Enum\EventStatus;
use KLXM\Scheduler\Domain\Event;
use KLXM\Scheduler\Domain\Location;
use KLXM\Scheduler\Ical\EventParser;
use KLXM\Scheduler\Ical\EventSerializer;
use KLXM\Scheduler\Ical\InvalidIcalException;
use KLXM\Scheduler\Ical\TimezoneBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class IcalRoundTripTest extends TestCase
{
    private const string CLIENT_ICS = <<<'ICS'
        BEGIN:VCALENDAR
        VERSION:2.0
        PRODID:-//Apple Inc.//macOS 15//EN
        BEGIN:VEVENT
        UID:ABC-123
        DTSTART;TZID=Europe/Berlin:20261005T090000
        DTEND;TZID=Europe/Berlin:20261005T100000
        RRULE:FREQ=WEEKLY;COUNT=4
        EXDATE;TZID=Europe/Berlin:20261019T090000
        SUMMARY:Teamrunde
        DESCRIPTION:Bitte pünktlich\nRaum 2
        LOCATION:Lehrerzimmer
        STATUS:TENTATIVE
        CATEGORIES:Team,Intern
        X-APPLE-TRAVEL-ADVISORY-BEHAVIOR:AUTOMATIC
        BEGIN:VALARM
        ACTION:DISPLAY
        TRIGGER:-PT15M
        DESCRIPTION:Erinnerung
        END:VALARM
        END:VEVENT
        BEGIN:VEVENT
        UID:ABC-123
        RECURRENCE-ID;TZID=Europe/Berlin:20261012T090000
        DTSTART;TZID=Europe/Berlin:20261012T110000
        DTEND;TZID=Europe/Berlin:20261012T120000
        SUMMARY:Teamrunde (verschoben)
        END:VEVENT
        END:VCALENDAR
        ICS;

    private function ics(): string
    {
        return str_replace("\n", "\r\n", preg_replace('/^ {8}/m', '', self::CLIENT_ICS) ?? '');
    }

    #[Test]
    public function parsesClientEventIntoModelAndKeepsUnknownParts(): void
    {
        $parser = new EventParser();
        $parsed = $parser->parse($this->ics());
        self::assertCount(1, $parsed);

        $event = new Event();
        $parser->apply($parsed[0], $event, 1, 'Europe/Berlin');

        self::assertSame('ABC-123', $event->uid);
        self::assertSame('Teamrunde', $event->title(1));
        self::assertSame('2026-10-05 09:00 Europe/Berlin', $event->dtstart->format('Y-m-d H:i e'));
        self::assertFalse($event->allDay);
        self::assertSame('FREQ=WEEKLY;COUNT=4', $event->rrule);
        self::assertSame(['20261019T090000'], $event->exdates);
        self::assertSame(EventStatus::Tentative, $event->status);
        self::assertSame(['Team', 'Intern'], $event->categories);
        self::assertSame('Lehrerzimmer', $event->locationText);
        self::assertSame("Bitte pünktlich\nRaum 2", $event->translation(1)?->plainDescription);
        self::assertStringContainsString('VALARM', (string) $event->extraIcal);
        self::assertStringContainsString('X-APPLE-TRAVEL-ADVISORY-BEHAVIOR', (string) $event->extraIcal);

        self::assertArrayHasKey('20261012T090000', $event->overrides);
        $override = $event->overrides['20261012T090000'];
        self::assertSame('2026-10-12 11:00', $override->dtstart->format('Y-m-d H:i'));
        self::assertSame('Teamrunde (verschoben)', $override->translations[1]['title'] ?? null);
    }

    #[Test]
    public function serializedEventSurvivesAnotherParse(): void
    {
        $parser = new EventParser();
        $event = new Event();
        $parser->apply($parser->parse($this->ics())[0], $event, 1, 'Europe/Berlin');

        $serializer = new EventSerializer();
        $vcalendar = $serializer->createCalendar('Test', 'Europe/Berlin');
        new TimezoneBuilder()->add($vcalendar, 'Europe/Berlin', new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2027-12-31'));
        $serializer->addEvent($vcalendar, $event, 1);
        $ics = $vcalendar->serialize();

        self::assertSame([], array_map(static fn (array $m): string => $m['message'], $vcalendar->validate()), 'vobject meldet keine Validierungsfehler');
        self::assertStringContainsString('BEGIN:VTIMEZONE', $ics);
        self::assertStringContainsString('BEGIN:VALARM', $ics);
        self::assertStringContainsString('RECURRENCE-ID;TZID=Europe/Berlin:20261012T090000', $ics);

        $again = new Event();
        $parser->apply($parser->parse($ics)[0], $again, 1, 'Europe/Berlin');
        self::assertSame($event->rrule, $again->rrule);
        self::assertSame($event->exdates, $again->exdates);
        self::assertSame(array_keys($event->overrides), array_keys($again->overrides));
        self::assertSame($event->extraIcal, $again->extraIcal);
    }

    #[Test]
    public function allDayEventIsWrittenAsDateWithExclusiveEnd(): void
    {
        $event = new Event()->scheduleAllDay(new DateTimeImmutable('2026-10-12'), new DateTimeImmutable('2026-10-14'));
        $event->uid = 'all-day';
        $event->translate(1)->title = 'Projekttage';

        $location = new Location();
        $location->name = 'Schule';
        $location->city = 'Musterstadt';
        $location->latitude = 51.5;
        $location->longitude = 7.4;

        $serializer = new EventSerializer();
        $vcalendar = $serializer->createCalendar();
        $serializer->addEvent($vcalendar, $event, 1, $location);
        $ics = $vcalendar->serialize();

        self::assertStringContainsString('DTSTART;VALUE=DATE:20261012', $ics);
        self::assertStringContainsString('DTEND;VALUE=DATE:20261015', $ics);
        self::assertStringContainsString('LOCATION:Schule\, Musterstadt', $ics);
        self::assertStringContainsString('GEO:51.5;7.4', $ics);
    }

    #[Test]
    public function utcAndFloatingTimesGetSensibleTimezones(): void
    {
        $ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:utc\r\nDTSTART:20261005T070000Z\r\nDTEND:20261005T080000Z\r\nSUMMARY:UTC\r\nEND:VEVENT\r\n"
            . "BEGIN:VEVENT\r\nUID:floating\r\nDTSTART:20261005T090000\r\nDURATION:PT90M\r\nSUMMARY:Floating\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
        $parser = new EventParser();
        [$utc, $floating] = $parser->parse($ics);

        $a = new Event();
        $parser->apply($utc, $a, 1, 'Europe/Berlin');
        self::assertSame('UTC', $a->timezone);
        self::assertSame('07:00', $a->dtstart->format('H:i'));

        $b = new Event();
        $parser->apply($floating, $b, 1, 'Europe/Berlin');
        self::assertSame('Europe/Berlin', $b->timezone);
        self::assertSame('2026-10-05 10:30', $b->dtend->format('Y-m-d H:i'));
    }

    #[Test]
    public function rejectsGarbage(): void
    {
        $this->expectException(InvalidIcalException::class);
        new EventParser()->parse('kein Kalender');
    }
}
