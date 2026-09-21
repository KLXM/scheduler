<?php

declare(strict_types=1);

namespace KLXM\Scheduler\Tests\Redaxo;

use DateTimeImmutable;
use DateTimeZone;
use KLXM\Scheduler\Domain\Enum\EventStatus;
use KLXM\Scheduler\Domain\Event;
use KLXM\Scheduler\Domain\Occurrence;
use KLXM\Scheduler\Scheduler;
use KLXM\Scheduler\Service\ValidationException;
use PHPUnit\Framework\Attributes\Test;

final class EventPersistenceTest extends RedaxoTestCase
{
    private function at(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable($time, new DateTimeZone('Europe/Berlin'));
    }

    private function event(string $title, DateTimeImmutable $start, DateTimeImmutable $end, bool $allDay = false): Event
    {
        $event = new Event($start, $end, $allDay);
        $event->calendarId = (int) $this->calendar->id;
        $event->translate(1)->title = $title;

        return $event;
    }

    #[Test]
    public function savesAndReloadsEventWithTranslationsAndLocalTimes(): void
    {
        $event = $this->event('Elternabend', $this->at('2026-11-03 19:00'), $this->at('2026-11-03 21:00'));
        $event->translate(1)->teaser = 'Klasse 5b';
        $event->categories = ['Schule', 'Eltern'];
        $event->custom = ['room' => 'Aula'];
        Scheduler::events()->save($event);

        $loaded = Scheduler::events()->findOrFail((int) $event->id);

        self::assertNotSame('', $loaded->uid);
        self::assertSame('Elternabend', $loaded->title(1));
        self::assertSame('Klasse 5b', $loaded->translation(1)?->teaser);
        self::assertSame('2026-11-03 19:00 Europe/Berlin', $loaded->dtstart->format('Y-m-d H:i e'));
        self::assertSame(['Schule', 'Eltern'], $loaded->categories);
        self::assertSame('Aula', $loaded->custom('room'));
        self::assertSame(EventStatus::Confirmed, $loaded->status);
        self::assertNotNull($loaded->createdAt);
    }

    #[Test]
    public function rejectsEventWithoutTitleOrWithBrokenRule(): void
    {
        $event = new Event($this->at('2026-11-03 19:00'), $this->at('2026-11-03 21:00'));
        $event->calendarId = (int) $this->calendar->id;
        $event->rrule = 'FREQ=NEVER';

        try {
            Scheduler::events()->save($event);
            self::fail('ValidationException erwartet');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('title', $e->errors);
            self::assertArrayHasKey('rrule', $e->errors);
        }
        self::assertNull($event->id);
    }

    #[Test]
    public function indexesSeriesAndAnswersRangeQueries(): void
    {
        $series = $this->event('AG Schach', $this->at('2026-10-06'), $this->at('2026-10-07'), true);
        $series->rrule = 'FREQ=WEEKLY;UNTIL=20261027';
        $series->exclude('20261013');
        Scheduler::events()->save($series);

        $single = $this->event('Konferenz', $this->at('2026-10-20 14:00'), $this->at('2026-10-20 16:00'));
        Scheduler::events()->save($single);

        $result = Scheduler::occurrences()
            ->inCalendars((int) $this->calendar->id)
            ->between('2026-10-01', '2026-11-01')
            ->get();

        self::assertSame(
            ['2026-10-06 AG Schach', '2026-10-20 AG Schach', '2026-10-20 Konferenz', '2026-10-27 AG Schach'],
            array_map(static fn (Occurrence $o): string => $o->start->format('Y-m-d') . ' ' . $o->title(1), $result),
        );
        self::assertTrue($result[0]->allDay);
        self::assertSame('2026-10-06', $result[0]->lastDay->format('Y-m-d'));

        // Ein ganztägiger Termin am 20.10. darf eine Abfrage ab dem 21.10. nicht mehr treffen.
        $after = Scheduler::occurrences()->inCalendars((int) $this->calendar->id)->between('2026-10-21', '2026-10-27')->count();
        self::assertSame(0, $after);

        $page = Scheduler::occurrences()->inCalendars((int) $this->calendar->id)->between('2026-10-01', '2026-11-01')->paginate(2, 3);
        self::assertSame(4, $page->total);
        self::assertCount(1, $page->items);
    }

    #[Test]
    public function unpublishedEventsAreHiddenFromFrontendQueries(): void
    {
        $event = $this->event('Intern', $this->at('2026-10-20 14:00'), $this->at('2026-10-20 16:00'));
        $event->published = false;
        Scheduler::events()->save($event);

        $query = Scheduler::occurrences()->inCalendars((int) $this->calendar->id)->between('2026-10-01', '2026-11-01');

        self::assertSame(0, $query->count());
        self::assertSame(1, (clone $query)->includeUnpublished()->count());
    }

    #[Test]
    public function updateBumpsSequenceAndReindexes(): void
    {
        $event = $this->event('Probe', $this->at('2026-10-20 14:00'), $this->at('2026-10-20 16:00'));
        Scheduler::events()->save($event);
        $etag = $event->etag;

        $event->schedule($this->at('2026-10-22 10:00'), $this->at('2026-10-22 11:00'));
        Scheduler::events()->save($event);

        self::assertSame(1, $event->sequence);
        self::assertNotSame($etag, $event->etag);
        $occurrence = Scheduler::occurrences()->forEvents((int) $event->id)->first();
        self::assertSame('2026-10-22 10:00', $occurrence?->start->format('Y-m-d H:i'));
    }

    #[Test]
    public function filtersByCategoryCustomFieldAndSearch(): void
    {
        $a = $this->event('Sommerfest', $this->at('2026-07-01 15:00'), $this->at('2026-07-01 20:00'));
        $a->categories = ['Fest'];
        $a->custom = ['room' => 'Hof'];
        Scheduler::events()->save($a);
        $b = $this->event('Zeugniskonferenz', $this->at('2026-07-02 15:00'), $this->at('2026-07-02 17:00'));
        Scheduler::events()->save($b);

        $base = fn () => Scheduler::occurrences()->inCalendars((int) $this->calendar->id)->between('2026-07-01', '2026-08-01');

        self::assertSame(['Sommerfest'], array_map(static fn (Occurrence $o): string => $o->title(1), $base()->withCategory('Fest')->get()));
        self::assertSame(1, $base()->whereCustom('room', 'Hof')->count());
        self::assertSame(['Zeugniskonferenz'], array_map(static fn (Occurrence $o): string => $o->title(1), $base()->search('zeugnis', 1)->get()));
    }

    #[Test]
    public function changingTheRuleDropsOverridesOfVanishedOccurrences(): void
    {
        $event = $this->event('Serie', $this->at('2026-10-06 16:00'), $this->at('2026-10-06 17:00'));
        $event->rrule = 'FREQ=WEEKLY;COUNT=4';
        Scheduler::events()->save($event);

        new \KLXM\Scheduler\Recurrence\SeriesEditor()->move($event, '20261013T160000', $this->at('2026-10-14 10:00'), $this->at('2026-10-14 11:00'), false, \KLXM\Scheduler\Recurrence\EditScope::This);
        Scheduler::events()->save($event);
        self::assertCount(1, Scheduler::events()->findOrFail((int) $event->id)->overrides);

        $event->rrule = 'FREQ=WEEKLY;BYDAY=TH;COUNT=4';
        Scheduler::events()->save($event);

        self::assertSame([], Scheduler::events()->findOrFail((int) $event->id)->overrides);
    }

    #[Test]
    public function rawConditionsInSubqueriesReferToTheOuterTable(): void
    {
        $future = $this->event('Kommt noch', $this->at('2031-05-01 10:00'), $this->at('2031-05-01 11:00'));
        Scheduler::events()->save($future);
        $past = $this->event('Vorbei', $this->at('2020-05-01 10:00'), $this->at('2020-05-01 11:00'));
        Scheduler::events()->save($past);

        $occurrences = Scheduler::em()->connection->table('scheduler_occurrence');
        $upcoming = Scheduler::events()->query()
            ->where('calendarId', (int) $this->calendar->id)
            ->whereRaw('EXISTS (SELECT 1 FROM ' . $occurrences . ' o WHERE o.`event_id` = {id} AND o.`end_utc` >= ?)', ['2026-01-01 00:00:00'])
            ->get();

        self::assertSame(['Kommt noch'], array_map(static fn (Event $e): string => $e->title(1), $upcoming));
    }
}
