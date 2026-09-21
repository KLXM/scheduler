<?php

declare(strict_types=1);

namespace KLXM\Scheduler\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use KLXM\Scheduler\Domain\Event;
use KLXM\Scheduler\Domain\EventOverride;
use KLXM\Scheduler\Recurrence\ExpandedOccurrence;
use KLXM\Scheduler\Recurrence\Expander;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ExpanderTest extends TestCase
{
    private const string TZ = 'Europe/Berlin';

    private function at(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable($time, new DateTimeZone(self::TZ));
    }

    /**
     * @param list<ExpandedOccurrence> $occurrences
     * @return list<string>
     */
    private function starts(array $occurrences, string $format = 'Y-m-d H:i'): array
    {
        return array_map(static fn (ExpandedOccurrence $o): string => $o->start->format($format), $occurrences);
    }

    #[Test]
    public function singleAllDayEventCoversExactlyOneDay(): void
    {
        $event = new Event()->scheduleAllDay($this->at('2026-10-05'));
        $event->uid = 'single';

        $result = new Expander()->expand($event, $this->at('2026-10-01'), $this->at('2026-11-01'));

        self::assertCount(1, $result);
        self::assertSame('2026-10-05', $result[0]->start->format('Y-m-d'));
        self::assertSame('2026-10-06', $result[0]->end->format('Y-m-d'), 'Ende ist exklusiv');
        self::assertTrue($result[0]->allDay);
    }

    #[Test]
    public function singleEventOutsideWindowIsSkipped(): void
    {
        $event = new Event($this->at('2026-10-05 09:00'), $this->at('2026-10-05 10:00'));

        self::assertSame([], new Expander()->expand($event, $this->at('2026-11-01'), $this->at('2026-12-01')));
    }

    #[Test]
    public function weeklyAllDaySeriesRespectsUntilAndExdate(): void
    {
        $event = new Event()->scheduleAllDay($this->at('2026-10-06'));
        $event->uid = 'weekly';
        $event->rrule = 'FREQ=WEEKLY;UNTIL=20261027';
        $event->exclude('20261013');

        $result = new Expander()->expand($event, $this->at('2026-10-01'), $this->at('2026-12-01'));

        self::assertSame(['2026-10-06', '2026-10-20', '2026-10-27'], $this->starts($result, 'Y-m-d'));
        self::assertSame('20261020', $result[1]->recurrenceKey);
    }

    #[Test]
    public function timedSeriesKeepsWallClockAcrossDaylightSaving(): void
    {
        $event = new Event($this->at('2026-10-20 18:00'), $this->at('2026-10-20 19:30'));
        $event->uid = 'dst';
        $event->rrule = 'FREQ=WEEKLY;COUNT=3';

        $result = new Expander()->expand($event, $this->at('2026-10-01'), $this->at('2026-12-01'));

        // Die Zeitumstellung am 25.10.2026 darf die Uhrzeit nicht verschieben.
        self::assertSame(['2026-10-20 18:00', '2026-10-27 18:00', '2026-11-03 18:00'], $this->starts($result));
        self::assertSame('+02:00', $result[0]->start->format('P'));
        self::assertSame('+01:00', $result[1]->start->format('P'));
        self::assertSame('2026-10-27 19:30', $result[1]->end->format('Y-m-d H:i'));
    }

    #[Test]
    public function monthlyOnThe31stSkipsShortMonths(): void
    {
        $event = new Event()->scheduleAllDay($this->at('2026-01-31'));
        $event->uid = 'monthly';
        $event->rrule = 'FREQ=MONTHLY;BYMONTHDAY=31';

        $result = new Expander()->expand($event, $this->at('2026-01-01'), $this->at('2026-06-01'));

        self::assertSame(['2026-01-31', '2026-03-31', '2026-05-31'], $this->starts($result, 'Y-m-d'));
    }

    #[Test]
    public function overrideMovesOneOccurrence(): void
    {
        $event = new Event($this->at('2026-10-05 09:00'), $this->at('2026-10-05 10:00'));
        $event->uid = 'override';
        $event->rrule = 'FREQ=DAILY;COUNT=3';

        $override = new EventOverride();
        $override->timezone = self::TZ;
        $override->recurrenceId = $this->at('2026-10-06 09:00');
        $override->dtstart = $this->at('2026-10-06 14:00');
        $override->dtend = $this->at('2026-10-06 15:00');
        $event->overrides['20261006T090000'] = $override;

        $result = new Expander()->expand($event, $this->at('2026-10-01'), $this->at('2026-11-01'));

        self::assertSame(['2026-10-05 09:00', '2026-10-06 14:00', '2026-10-07 09:00'], $this->starts($result));
        self::assertTrue($result[1]->isOverride);
        self::assertSame('20261006T090000', $result[1]->recurrenceKey);
        self::assertFalse($result[0]->isOverride);
    }

    #[Test]
    public function infiniteSeriesIsBoundedByWindowAndLimit(): void
    {
        $event = new Event($this->at('2020-01-01 08:00'), $this->at('2020-01-01 08:15'));
        $event->uid = 'infinite';
        $event->rrule = 'FREQ=DAILY';

        $expander = new Expander();
        self::assertCount(31, $expander->expand($event, $this->at('2026-10-01'), $this->at('2026-11-01')));
        self::assertCount(5, $expander->next($event, $this->at('2026-10-01'), 5));
    }

    #[Test]
    public function rdateAddsExtraOccurrence(): void
    {
        $event = new Event()->scheduleAllDay($this->at('2026-10-05'));
        $event->uid = 'rdate';
        $event->rdates = ['20261009'];

        $result = new Expander()->expand($event, $this->at('2026-10-01'), $this->at('2026-11-01'));

        self::assertSame(['2026-10-05', '2026-10-09'], $this->starts($result, 'Y-m-d'));
    }
}
