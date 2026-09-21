<?php

declare(strict_types=1);

namespace KLXM\Scheduler\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use KLXM\Scheduler\Domain\Event;
use KLXM\Scheduler\Recurrence\EditScope;
use KLXM\Scheduler\Recurrence\ExpandedOccurrence;
use KLXM\Scheduler\Recurrence\Expander;
use KLXM\Scheduler\Recurrence\SeriesEditor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SeriesEditorTest extends TestCase
{
    private function at(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable($time, new DateTimeZone('Europe/Berlin'));
    }

    private function weekly(string $rrule = 'FREQ=WEEKLY;COUNT=5'): Event
    {
        $event = new Event($this->at('2026-10-05 09:00'), $this->at('2026-10-05 10:00'));
        $event->uid = 'series';
        $event->rrule = $rrule;
        $event->translate(1)->title = 'Serie';

        return $event;
    }

    /**
     * @return list<string>
     */
    private function days(Event $event): array
    {
        return array_map(
            static fn (ExpandedOccurrence $o): string => $o->start->format('m-d H:i'),
            new Expander()->expand($event, $this->at('2026-01-01'), $this->at('2027-12-31')),
        );
    }

    #[Test]
    public function movingOneOccurrenceCreatesOverride(): void
    {
        $event = $this->weekly();
        $tail = new SeriesEditor()->move($event, '20261012T090000', $this->at('2026-10-13 14:00'), $this->at('2026-10-13 15:00'), false, EditScope::This);

        self::assertNull($tail);
        self::assertSame(['10-05 09:00', '10-13 14:00', '10-19 09:00', '10-26 09:00', '11-02 09:00'], $this->days($event));
    }

    #[Test]
    public function movingFollowingSplitsSeriesAndKeepsTotalCount(): void
    {
        $event = $this->weekly();
        $event->exclude('20261026T090000');

        $tail = new SeriesEditor()->move($event, '20261019T090000', $this->at('2026-10-19 11:00'), $this->at('2026-10-19 12:30'), false, EditScope::Following);

        self::assertNotNull($tail);
        self::assertSame(['10-05 09:00', '10-12 09:00'], $this->days($event));
        self::assertStringContainsString('UNTIL=', (string) $event->rrule);
        self::assertStringNotContainsString('COUNT', (string) $event->rrule);

        // Drei Vorkommen bleiben für die neue Serie, eines davon war ausgeschlossen und wandert mit.
        self::assertSame('FREQ=WEEKLY;COUNT=3', $tail->rrule);
        self::assertSame(['20261026T110000'], $tail->exdates);
        self::assertSame(['10-19 11:00', '11-02 11:00'], $this->days($tail));
        self::assertSame('', $tail->uid);
        self::assertNull($tail->id);
        self::assertSame('Serie', $tail->title(1));
        self::assertSame(5400, $tail->duration);
    }

    #[Test]
    public function movingAllShiftsWholeSeriesIncludingExceptions(): void
    {
        $event = $this->weekly();
        $event->exclude('20261012T090000');

        new SeriesEditor()->move($event, '20261019T090000', $this->at('2026-10-20 10:00'), $this->at('2026-10-20 11:00'), false, EditScope::All);

        self::assertSame(['20261013T100000'], $event->exdates);
        self::assertSame(['10-06 10:00', '10-20 10:00', '10-27 10:00', '11-03 10:00'], $this->days($event));
    }

    #[Test]
    public function removingSingleAndFollowingOccurrences(): void
    {
        $editor = new SeriesEditor();

        $event = $this->weekly('FREQ=WEEKLY;UNTIL=20261102T080000Z');
        self::assertFalse($editor->remove($event, '20261012T090000', EditScope::This));
        self::assertSame(['10-05 09:00', '10-19 09:00', '10-26 09:00', '11-02 09:00'], $this->days($event));

        self::assertFalse($editor->remove($event, '20261026T090000', EditScope::Following));
        self::assertSame(['10-05 09:00', '10-19 09:00'], $this->days($event));

        self::assertTrue($editor->remove($event, '20261005T090000', EditScope::Following), 'Ab dem ersten Vorkommen heißt: ganze Serie');
        self::assertTrue($editor->remove($event, '20261019T090000', EditScope::All));
    }

    #[Test]
    public function allDaySeriesSplitUsesDateUntil(): void
    {
        $event = new Event()->scheduleAllDay($this->at('2026-10-06'));
        $event->uid = 'all-day';
        $event->rrule = 'FREQ=WEEKLY';

        $tail = new SeriesEditor()->split($event, '20261020');

        self::assertSame('FREQ=WEEKLY;UNTIL=20261019', $event->rrule);
        self::assertSame('FREQ=WEEKLY', $tail->rrule);
        self::assertSame('2026-10-20', $tail->dtstart->format('Y-m-d'));
    }
}
