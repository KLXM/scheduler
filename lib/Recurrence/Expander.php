<?php

declare(strict_types=1);

namespace KLXM\Scheduler\Recurrence;

use DateTimeImmutable;
use DateTimeZone;
use KLXM\Scheduler\Domain\Event;
use KLXM\Scheduler\Ical\EventSerializer;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;

/**
 * Berechnet die Vorkommen eines Termins in einem Zeitfenster.
 *
 * Die Expansion übernimmt sabre/vobject inklusive EXDATE, RDATE und Einzelausnahmen,
 * damit Anzeige, Export und CalDAV garantiert dieselben Vorkommen sehen.
 */
final class Expander
{
    public function __construct(
        private readonly EventSerializer $serializer = new EventSerializer(),
    ) {}

    /**
     * @return list<ExpandedOccurrence> aufsteigend nach Beginn
     */
    public function expand(Event $event, DateTimeImmutable $from, DateTimeImmutable $to, int $max = 5000): array
    {
        $zone = new DateTimeZone($event->timezone);

        if (!$event->isRecurring) {
            if ($event->dtstart >= $to || $this->effectiveEnd($event->dtstart, $event->dtend) <= $from) {
                return [];
            }

            return [new ExpandedOccurrence($event->dtstart, $event->dtend, $event->allDay, $event->recurrenceKey($event->dtstart), false)];
        }

        $vcalendar = new VCalendar();
        $this->serializer->addTimingOnly($vcalendar, $event);
        $expanded = $vcalendar->expand($from, $to, $zone);

        $occurrences = [];
        foreach ($expanded->select('VEVENT') as $vevent) {
            if (!$vevent instanceof VEvent || null === $vevent->DTSTART) {
                continue;
            }
            $occurrences[] = $this->fromVEvent($event, $vevent, $zone);
            if (count($occurrences) >= $max) {
                break;
            }
        }

        usort($occurrences, static fn (ExpandedOccurrence $a, ExpandedOccurrence $b): int => $a->start <=> $b->start);

        return $occurrences;
    }

    /**
     * Die nächsten Vorkommen ab einem Zeitpunkt, für Vorschauen im Editor.
     *
     * @return list<ExpandedOccurrence>
     */
    public function next(Event $event, DateTimeImmutable $from, int $limit = 5): array
    {
        $found = [];
        // Das Fenster wächst, bis genug Vorkommen gefunden sind oder 50 Jahre erreicht sind.
        foreach ([1, 5, 50] as $years) {
            $found = $this->expand($event, $from, $from->modify('+' . $years . ' years'), $limit);
            if (count($found) >= $limit || !$event->isRecurring) {
                return $found;
            }
        }

        return $found;
    }

    private function fromVEvent(Event $event, VEvent $vevent, DateTimeZone $zone): ExpandedOccurrence
    {
        $allDay = !$vevent->DTSTART->hasTime();
        $start = $this->local($vevent->DTSTART->getDateTime($zone), $allDay, $zone);
        $end = null !== $vevent->DTEND
            ? $this->local($vevent->DTEND->getDateTime($zone), $allDay, $zone)
            : $start->modify($allDay ? '+1 day' : '+0 seconds');

        $original = null !== $vevent->{'RECURRENCE-ID'}
            ? $this->local($vevent->{'RECURRENCE-ID'}->getDateTime($zone), $event->allDay, $zone)
            : $start;
        $key = $event->recurrenceKey($original);

        return new ExpandedOccurrence($start, $end, $allDay, $key, isset($event->overrides[$key]));
    }

    private function local(\DateTimeInterface $value, bool $allDay, DateTimeZone $zone): DateTimeImmutable
    {
        $value = DateTimeImmutable::createFromInterface($value);

        return $allDay
            ? new DateTimeImmutable($value->format('Y-m-d') . ' 00:00:00', $zone)
            : $value->setTimezone($zone);
    }

    /** Termine ohne Dauer zählen für Bereichsprüfungen als eine Sekunde lang. */
    private function effectiveEnd(DateTimeImmutable $start, DateTimeImmutable $end): DateTimeImmutable
    {
        return $end > $start ? $end : $start->modify('+1 second');
    }
}
