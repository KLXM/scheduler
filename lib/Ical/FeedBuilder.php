<?php

declare(strict_types=1);

namespace KLXM\Scheduler\Ical;

use DateTimeImmutable;
use DateTimeZone;
use KLXM\Scheduler\Domain\Calendar;
use KLXM\Scheduler\Domain\Enum\SchemaTarget;
use KLXM\Scheduler\Domain\Enum\Visibility;
use KLXM\Scheduler\Domain\Event;
use KLXM\Scheduler\Domain\Occurrence;
use KLXM\Scheduler\Scheduler;
use KLXM\Scheduler\Orm\MetadataFactory;

/**
 * Baut ICS-Feeds. Serien gehen als ein VEVENT mit RRULE, EXDATE und Einzelausnahmen hinaus;
 * welche Termine in ein Zeitfenster fallen, beantwortet der Vorkommens-Index.
 */
final class FeedBuilder
{
    public function __construct(
        private readonly EventSerializer $serializer = new EventSerializer(),
        private readonly TimezoneBuilder $timezones = new TimezoneBuilder(),
    ) {}

    /**
     * @param list<Calendar> $calendars
     * @param bool $publicOnly nur veröffentlichte Termine mit öffentlicher Vertraulichkeit
     */
    public function forCalendars(array $calendars, int $clangId, DateTimeImmutable $from, DateTimeImmutable $to, bool $publicOnly = true, ?string $name = null): string
    {
        $calendarIds = array_map(static fn (Calendar $calendar): int => (int) $calendar->id, $calendars);
        $events = [] === $calendarIds ? [] : $this->eventsInWindow($calendarIds, $from, $to, $publicOnly);
        $timezone = $calendars[0]->timezone ?? Scheduler::settings()->defaultTimezone();

        return $this->build($events, $clangId, $name ?? (1 === count($calendars) ? $calendars[0]->name($clangId) : \rex::getServerName()), $timezone, $from, $to);
    }

    public function forEvent(Event $event, int $clangId): string
    {
        return $this->build([$event], $clangId, null, $event->timezone, $event->dtstart->modify('-1 year'), $event->dtstart->modify('+5 years'));
    }

    /**
     * @param list<Event> $events
     */
    private function build(array $events, int $clangId, ?string $name, string $timezone, DateTimeImmutable $from, DateTimeImmutable $to): string
    {
        $vcalendar = $this->serializer->createCalendar($name, $timezone);
        $vcalendar->add('METHOD', 'PUBLISH');

        $usedZones = array_unique(array_map(static fn (Event $event): string => $event->timezone, array_filter($events, static fn (Event $e): bool => !$e->allDay)));
        foreach ($usedZones as $zone) {
            $this->timezones->add($vcalendar, $zone, $from, $to->modify('+3 years'));
        }

        $locations = Scheduler::locations()->findMany(array_values(array_filter(array_map(static fn (Event $e): ?int => $e->locationId, $events))));
        $icalFields = array_filter(Scheduler::schemas()->active(SchemaTarget::Event)->fields(), static fn ($node): bool => $node->ical && 'repeater' !== $node->type);
        $types = Scheduler::schemas()->types;

        foreach ($events as $event) {
            $properties = [];
            foreach ($icalFields as $fieldName => $node) {
                $value = $node->translatable ? ($event->translation($clangId)?->custom[$fieldName] ?? null) : ($event->custom[$fieldName] ?? null);
                if ($types->has($node->type) && !$types->get($node->type)->isEmpty($value)) {
                    $properties['X-SCHEDULER-' . strtoupper(str_replace('_', '-', $fieldName))] = $types->get($node->type)->toText($value, $node);
                }
            }
            $this->serializer->addEvent($vcalendar, $event, $clangId, $locations[$event->locationId ?? 0] ?? null, $properties);
        }

        return $vcalendar->serialize();
    }

    /**
     * @param list<int> $calendarIds
     * @return list<Event>
     */
    private function eventsInWindow(array $calendarIds, DateTimeImmutable $from, DateTimeImmutable $to, bool $publicOnly): array
    {
        $connection = Scheduler::em()->connection;
        $utc = new DateTimeZone('UTC');
        $rows = $connection->fetchAll(
            'SELECT DISTINCT `event_id` FROM ' . $connection->table(MetadataFactory::for(Occurrence::class)->table)
            . ' WHERE `calendar_id` IN (' . implode(', ', array_fill(0, count($calendarIds), '?')) . ') AND `end_utc` >= ? AND `start_utc` < ?',
            [...$calendarIds, $from->setTimezone($utc)->format('Y-m-d H:i:s'), $to->setTimezone($utc)->format('Y-m-d H:i:s')],
        );

        $query = Scheduler::events()->query()->whereIn('id', array_map(static fn (array $row): int => (int) $row['event_id'], $rows))->orderBy('dtstart');
        if ($publicOnly) {
            $query->where('published', true)->where('visibility', Visibility::Public);
        }

        return $query->get();
    }
}
