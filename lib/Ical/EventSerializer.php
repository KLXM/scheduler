<?php

declare(strict_types=1);

namespace KLXM\Scheduler\Ical;

use DateTimeImmutable;
use DateTimeZone;
use KLXM\Scheduler\Domain\Event;
use KLXM\Scheduler\Domain\EventOverride;
use KLXM\Scheduler\Domain\Location;
use KLXM\Scheduler\Recurrence\Rule;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\Reader;

/**
 * Wandelt Termine in iCalendar-Komponenten. Einzige Stelle, die VEVENTs erzeugt:
 * Export, CalDAV und die Berechnung der Vorkommen nutzen denselben Code.
 */
final class EventSerializer
{
    public const string PRODID = '-//KLXM//scheduler//DE';

    public function createCalendar(?string $name = null, ?string $timezone = null): VCalendar
    {
        $vcalendar = new VCalendar();
        $vcalendar->PRODID = self::PRODID;
        if (null !== $name && '' !== $name) {
            $vcalendar->add('X-WR-CALNAME', $name);
        }
        if (null !== $timezone) {
            $vcalendar->add('X-WR-TIMEZONE', $timezone);
        }

        return $vcalendar;
    }

    /**
     * Fügt den Termin samt Einzelausnahmen hinzu.
     *
     * @param array<string, string> $customProperties X-Eigenschaften aus freigegebenen Custom Fields
     */
    public function addEvent(VCalendar $vcalendar, Event $event, int $clangId, ?Location $location = null, array $customProperties = []): void
    {
        $master = $this->addTimingOnly($vcalendar, $event, withOverrides: false);
        $translation = $event->translation($clangId);

        $master->SUMMARY = $event->title($clangId);
        $description = $translation->plainDescription ?? '';
        if ('' !== $description) {
            $master->DESCRIPTION = $description;
        }

        $this->addCommon($master, $event, $location);
        foreach ($customProperties as $name => $value) {
            $master->add($name, $value);
        }
        $this->mergeExtra($master, $event->extraIcal);

        foreach ($event->overrides as $override) {
            $vevent = $this->addOverride($vcalendar, $event, $override);
            $texts = $override->translations[$clangId] ?? [];
            $vevent->SUMMARY = ($texts['title'] ?? '') !== '' ? $texts['title'] : $event->title($clangId);
            $overrideDescription = trim(strip_tags($texts['teaser'] ?? $texts['description'] ?? ''));
            if ('' !== $overrideDescription || '' !== $description) {
                $vevent->DESCRIPTION = '' !== $overrideDescription ? $overrideDescription : $description;
            }
            $this->addCommon($vevent, $event, $location, $override);
            $this->mergeExtra($vevent, $override->extraIcal);
        }
    }

    /**
     * Nur die für die Wiederholung relevanten Eigenschaften. Reicht zur Berechnung der Vorkommen.
     */
    public function addTimingOnly(VCalendar $vcalendar, Event $event, bool $withOverrides = true): VEvent
    {
        /** @var VEvent $vevent */
        $vevent = $vcalendar->add('VEVENT', ['UID' => $this->uid($event)]);
        $this->addPeriod($vevent, $event->dtstart, $event->dtend, $event->allDay);

        $zone = new DateTimeZone($event->timezone);
        if (null !== $event->rrule) {
            $vevent->add('RRULE', (string) Rule::parse($event->rrule)->normalizedFor($event->allDay, $zone));
        }
        foreach (['EXDATE' => $event->exdates, 'RDATE' => $event->rdates] as $property => $keys) {
            $dates = array_map(fn (string $key): DateTimeImmutable => $this->fromKey($key, $zone), $keys);
            if ([] !== $dates) {
                $vevent->add($property, $dates, $event->allDay ? ['VALUE' => 'DATE'] : []);
            }
        }

        if ($withOverrides) {
            foreach ($event->overrides as $override) {
                $this->addOverride($vcalendar, $event, $override);
            }
        }

        return $vevent;
    }

    public function fromKey(string $key, DateTimeZone $zone): DateTimeImmutable
    {
        $format = 8 === strlen($key) ? '!Ymd' : '!Ymd\THis';

        return DateTimeImmutable::createFromFormat($format, $key, $zone)
            ?: throw new \InvalidArgumentException(sprintf('Ungültiger Vorkommens-Schlüssel "%s".', $key));
    }

    /** Noch nicht gespeicherte Termine haben keine UID; für Vorschauen genügt ein Platzhalter. */
    private function uid(Event $event): string
    {
        return '' !== $event->uid ? $event->uid : 'scheduler-unsaved';
    }

    private function addOverride(VCalendar $vcalendar, Event $event, EventOverride $override): VEvent
    {
        /** @var VEvent $vevent */
        $vevent = $vcalendar->add('VEVENT', ['UID' => $this->uid($event)]);
        $vevent->add('RECURRENCE-ID', $override->recurrenceId, $event->allDay ? ['VALUE' => 'DATE'] : []);
        $this->addPeriod($vevent, $override->dtstart, $override->dtend, $override->allDay);

        return $vevent;
    }

    private function addPeriod(VEvent $vevent, DateTimeImmutable $start, DateTimeImmutable $end, bool $allDay): void
    {
        $params = $allDay ? ['VALUE' => 'DATE'] : [];
        $vevent->add('DTSTART', $start, $params);
        if ($end > $start) {
            $vevent->add('DTEND', $end, $params);
        }
    }

    private function addCommon(VEvent $vevent, Event $event, ?Location $location, ?EventOverride $override = null): void
    {
        $utc = new DateTimeZone('UTC');
        $modified = ($event->updatedAt ?? new DateTimeImmutable('now', $utc))->setTimezone($utc);
        $vevent->DTSTAMP = $modified;
        $vevent->add('LAST-MODIFIED', $modified);
        if (null !== $event->createdAt) {
            $vevent->add('CREATED', $event->createdAt->setTimezone($utc));
        }
        $vevent->SEQUENCE = (string) $event->sequence;
        $vevent->STATUS = ($override->status ?? $event->status)->value;
        $vevent->add('CLASS', $event->visibility->value);
        $vevent->TRANSP = $event->transparency->value;

        $locationText = $override->locationText ?? $location->label ?? $event->locationText;
        if (null !== $locationText && '' !== $locationText) {
            $vevent->LOCATION = $locationText;
        }
        if (null === $override?->locationText && true === $location?->hasGeo) {
            $vevent->add('GEO', [(float) $location->latitude, (float) $location->longitude]);
        }
        if (null !== $event->url && '' !== $event->url) {
            $vevent->URL = $event->url;
        }
        if (null !== $event->organizer && '' !== $event->organizer) {
            $vevent->ORGANIZER = $event->organizer;
        }
        if ([] !== $event->categories) {
            $vevent->add('CATEGORIES', $event->categories);
        }
    }

    /**
     * Hängt gespeicherte, von scheduler nicht modellierte Eigenschaften und Komponenten wieder an.
     */
    private function mergeExtra(VEvent $vevent, ?string $extraIcal): void
    {
        if (null === $extraIcal || '' === trim($extraIcal)) {
            return;
        }

        try {
            $wrapper = Reader::read("BEGIN:VCALENDAR\r\nVERSION:2.0\r\n" . trim($extraIcal) . "\r\nEND:VCALENDAR\r\n", Reader::OPTION_FORGIVING);
        } catch (\Throwable) {
            return;
        }

        foreach ($wrapper->VEVENT?->children() ?? [] as $child) {
            $vevent->add(clone $child);
        }
    }
}
