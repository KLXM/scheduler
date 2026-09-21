<?php

declare(strict_types=1);

namespace KLXM\Scheduler\Recurrence;

use DateTimeImmutable;
use DateTimeZone;
use KLXM\Scheduler\Domain\Event;
use KLXM\Scheduler\Domain\EventOverride;
use KLXM\Scheduler\Ical\EventSerializer;
use Sabre\VObject\Recur\RRuleIterator;

/**
 * Setzt die drei Bearbeitungsarten eines Serientermins um: nur dieses Vorkommen, dieses und alle
 * folgenden, oder die ganze Serie. Arbeitet nur auf den Objekten; gespeichert wird vom Aufrufer.
 */
final class SeriesEditor
{
    public function __construct(
        private readonly EventSerializer $serializer = new EventSerializer(),
    ) {}

    /**
     * Verschiebt ein Vorkommen oder ändert seine Dauer.
     *
     * @param string $key Schlüssel des Vorkommens (Occurrence::$recurrenceKey)
     * @param DateTimeImmutable $end exklusives Ende
     * @return Event|null der abgespaltene neue Serientermin bei EditScope::Following, sonst null
     */
    public function move(Event $event, string $key, DateTimeImmutable $start, DateTimeImmutable $end, bool $allDay, EditScope $scope): ?Event
    {
        if (!$event->isRecurring) {
            $event->schedule($start, $end, $allDay);

            return null;
        }

        $zone = new DateTimeZone($event->timezone);
        $original = $this->serializer->fromKey($key, $zone);

        switch ($scope) {
            case EditScope::This:
                $override = $event->overrides[$key] ?? new EventOverride();
                $override->timezone = $event->timezone;
                $override->recurrenceId = $original;
                [$override->dtstart, $override->dtend] = $this->normalize($start, $end, $allDay, $zone);
                $override->allDay = $allDay;
                $event->overrides[$key] = $override;

                return null;

            case EditScope::All:
                // Die ganze Serie wandert um denselben Abstand wie das angefasste Vorkommen.
                $current = $event->overrides[$key]->dtstart ?? $original;
                [$start, $end] = $this->normalize($start, $end, $allDay, $zone);
                $shift = $current->diff($start);
                $duration = $start->diff($end);
                $newStart = $event->dtstart->add($shift);
                $this->shiftKeys($event, $shift, $allDay, $zone);
                $event->schedule($newStart, $newStart->add($duration), $allDay);

                return null;

            case EditScope::Following:
                $tail = $this->split($event, $key);
                [$start, $end] = $this->normalize($start, $end, $allDay, $zone);
                $shift = $original->diff($start);
                $this->shiftKeys($tail, $shift, $allDay, $zone);
                $tail->schedule($start, $end, $allDay);

                return $tail;
        }
    }

    /**
     * Entfernt ein Vorkommen, alle ab diesem Vorkommen oder meldet, dass die ganze Serie zu löschen ist.
     *
     * @return bool true, wenn der Aufrufer den Termin vollständig löschen soll
     */
    public function remove(Event $event, string $key, EditScope $scope): bool
    {
        if (!$event->isRecurring || EditScope::All === $scope) {
            return true;
        }

        if (EditScope::This === $scope) {
            $event->exclude($key);

            return false;
        }

        $firstKey = $event->recurrenceKey($event->dtstart);
        if ($key <= $firstKey) {
            return true;
        }
        $this->split($event, $key);

        return false;
    }

    /**
     * Beendet die Serie vor dem Vorkommen und liefert einen neuen, noch ungespeicherten Termin,
     * der die Serie ab dort fortsetzt. Ausnahmen ab dem Schnitt wandern mit.
     */
    public function split(Event $event, string $key): Event
    {
        $zone = new DateTimeZone($event->timezone);
        $cut = $this->serializer->fromKey($key, $zone);
        $rule = null !== $event->rrule ? Rule::parse($event->rrule) : null;

        $tail = clone $event;
        $this->resetIdentity($tail);
        $tail->schedule($cut, $cut->add($event->dtstart->diff($event->dtend)), $event->allDay);
        $tail->translations = array_map($this->cloneTranslation(...), $event->translations);

        if (null !== $rule) {
            if (null !== $rule->count) {
                // COUNT zählt alle von der Regel erzeugten Vorkommen; der Rest geht an die neue Serie.
                $before = $this->countBefore($event, $cut);
                $tail->rrule = (string) $rule->with('COUNT', (string) max(1, $rule->count - $before));
            }
            $until = $event->allDay ? $cut->modify('-1 day')->format('Ymd') : $cut->modify('-1 second')->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');
            $event->rrule = (string) $rule->with('COUNT', null)->with('UNTIL', $until);
        }

        [$event->exdates, $tail->exdates] = $this->partition($event->exdates, $key);
        [$event->rdates, $tail->rdates] = $this->partition($event->rdates, $key);
        $tail->overrides = [];
        foreach ($event->overrides as $overrideKey => $override) {
            if ($overrideKey >= $key) {
                $moved = clone $override;
                $this->resetIdentity($moved);
                $tail->overrides[$overrideKey] = $moved;
                unset($event->overrides[$overrideKey]);
            }
        }

        return $tail;
    }

    /**
     * Unabhängige, noch ungespeicherte Kopie eines Termins samt Übersetzungen und Ausnahmen.
     */
    public function duplicate(Event $event): Event
    {
        $copy = clone $event;
        $this->resetIdentity($copy);
        $copy->translations = array_map($this->cloneTranslation(...), $event->translations);
        $copy->overrides = array_map(function (EventOverride $override): EventOverride {
            $clone = clone $override;
            $this->resetIdentity($clone);

            return $clone;
        }, $event->overrides);

        return $copy;
    }

    private function countBefore(Event $event, DateTimeImmutable $cut): int
    {
        $iterator = new RRuleIterator((string) $event->rrule, $event->dtstart);
        $count = 0;
        while ($iterator->valid() && $iterator->current() < $cut) {
            ++$count;
            $iterator->next();
        }

        return $count;
    }

    /**
     * @param list<string> $keys
     * @return array{list<string>, list<string>} vor dem Schnitt, ab dem Schnitt
     */
    private function partition(array $keys, string $cutKey): array
    {
        return [
            array_values(array_filter($keys, static fn (string $k): bool => $k < $cutKey)),
            array_values(array_filter($keys, static fn (string $k): bool => $k >= $cutKey)),
        ];
    }

    /**
     * Verschiebt alle Vorkommens-Schlüssel um denselben Abstand wie den Serienbeginn.
     */
    private function shiftKeys(Event $event, \DateInterval $shift, bool $allDay, DateTimeZone $zone): void
    {
        $format = $allDay ? 'Ymd' : 'Ymd\THis';
        $move = fn (string $key): string => $this->serializer->fromKey($key, $zone)->add($shift)->format($format);

        $event->exdates = array_map($move, $event->exdates);
        $event->rdates = array_map($move, $event->rdates);

        $overrides = [];
        foreach ($event->overrides as $key => $override) {
            $override->recurrenceId = $override->recurrenceId->add($shift);
            $override->dtstart = $override->dtstart->add($shift);
            $override->dtend = $override->dtend->add($shift);
            $overrides[$move($key)] = $override;
        }
        $event->overrides = $overrides;
    }

    /**
     * @return array{DateTimeImmutable, DateTimeImmutable}
     */
    private function normalize(DateTimeImmutable $start, DateTimeImmutable $end, bool $allDay, DateTimeZone $zone): array
    {
        if ($allDay) {
            $start = new DateTimeImmutable($start->format('Y-m-d') . ' 00:00:00', $zone);
            $end = new DateTimeImmutable($end->format('Y-m-d') . ' 00:00:00', $zone);

            return [$start, $end > $start ? $end : $start->modify('+1 day')];
        }

        $start = $start->setTimezone($zone);
        $end = $end->setTimezone($zone);

        return [$start, $end >= $start ? $end : $start];
    }

    private function resetIdentity(object $entity): void
    {
        $reflection = new \ReflectionProperty($entity, 'id');
        $reflection->setValue($entity, null);
        if ($entity instanceof Event) {
            $entity->uid = '';
            $entity->sequence = 0;
            $entity->importSource = null;
            $entity->importRef = null;
        }
    }

    private function cloneTranslation(object $translation): object
    {
        $copy = clone $translation;
        $this->resetIdentity($copy);

        return $copy;
    }
}
