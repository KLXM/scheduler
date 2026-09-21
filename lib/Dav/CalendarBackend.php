<?php

declare(strict_types=1);

namespace KLXM\Scheduler\Dav;

use KLXM\Scheduler\I18n;
use DateTimeImmutable;
use KLXM\Dav\Context;
use KLXM\Dav\PrincipalBackend;
use KLXM\Scheduler\Domain\Calendar;
use KLXM\Scheduler\Domain\DavChange;
use KLXM\Scheduler\Domain\Event;
use KLXM\Scheduler\Scheduler;
use KLXM\Scheduler\Ical\EventParser;
use KLXM\Scheduler\Ical\EventSerializer;
use KLXM\Scheduler\Ical\InvalidIcalException;
use KLXM\Scheduler\Ical\TimezoneBuilder;
use KLXM\Scheduler\Orm\MetadataFactory;
use KLXM\Scheduler\Security\Access;
use KLXM\Scheduler\Service\ValidationException;
use Sabre\CalDAV\Backend\AbstractBackend;
use Sabre\CalDAV\Backend\SyncSupport;
use Sabre\CalDAV\Xml\Property\SupportedCalendarComponentSet;
use Sabre\DAV\Exception\BadRequest;
use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\PropPatch;

/**
 * CalDAV-Speicher auf Basis der scheduler-Kalender. Braucht das Addon dav, das Server und Anmeldung stellt. Jeder Kalender, den der Benutzer im Backend
 * pflegen darf, erscheint als Collection; jeder Termin samt Einzelausnahmen als eine .ics-Ressource.
 *
 * Geschrieben wird über dasselbe Repository wie im Backend: Validierung, Vorkommens-Index und
 * Extension Points greifen unverändert.
 *
 * @internal
 */
final class CalendarBackend extends AbstractBackend implements SyncSupport
{
    public function __construct(
        private readonly Context $context,
        private readonly int $clangId,
        private readonly EventSerializer $serializer = new EventSerializer(),
        private readonly EventParser $parser = new EventParser(),
        private readonly TimezoneBuilder $timezones = new TimezoneBuilder(),
    ) {}

    public function getCalendarsForUser($principalUri): array
    {
        $user = $this->context->user;
        if (null === $user || PrincipalBackend::PREFIX . '/' . $user->getLogin() !== $principalUri) {
            return [];
        }

        return array_map(fn (Calendar $calendar): array => [
            'id' => (int) $calendar->id,
            'uri' => $calendar->slug,
            'principaluri' => $principalUri,
            '{DAV:}displayname' => $calendar->name($this->clangId),
            '{urn:ietf:params:xml:ns:caldav}calendar-description' => (string) $calendar->description,
            '{urn:ietf:params:xml:ns:caldav}calendar-timezone' => null,
            '{http://apple.com/ns/ical/}calendar-color' => strtoupper($calendar->color) . 'FF',
            '{http://apple.com/ns/ical/}calendar-order' => $calendar->priority,
            '{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set' => new SupportedCalendarComponentSet(['VEVENT']),
            '{http://calendarserver.org/ns/}getctag' => 'scheduler-' . $calendar->syncToken,
            '{http://sabredav.org/ns}sync-token' => (string) $calendar->syncToken,
            '{http://sabredav.org/ns}read-only' => !$this->context->canWrite,
        ], Access::davCalendars($user));
    }

    public function createCalendar($principalUri, $calendarUri, array $properties): never
    {
        throw new Forbidden(I18n::t('dav_calendar_create_denied'));
    }

    public function updateCalendar($calendarId, PropPatch $propPatch): void
    {
        // Name und Farbe gehören dem Backend; Änderungswünsche der Clients werden stillschweigend ignoriert.
    }

    public function deleteCalendar($calendarId): never
    {
        throw new Forbidden(I18n::t('dav_calendar_delete_denied'));
    }

    public function getCalendarObjects($calendarId): array
    {
        $calendar = $this->calendar($calendarId);
        $rows = Scheduler::em()->connection->fetchAll(
            'SELECT `id`, `dav_uri`, `etag`, `updated_at` FROM ' . Scheduler::em()->connection->table(MetadataFactory::for(Event::class)->table) . ' WHERE `calendar_id` = ?',
            [(int) $calendar->id],
        );

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'uri' => (string) $row['dav_uri'],
            'etag' => '"' . $row['etag'] . '"',
            'lastmodified' => null !== $row['updated_at'] ? strtotime($row['updated_at'] . ' UTC') : null,
            'calendarid' => (int) $calendar->id,
            'component' => 'vevent',
        ], $rows);
    }

    public function getCalendarObject($calendarId, $objectUri): ?array
    {
        $event = Scheduler::events()->findByDavUri((int) $this->calendar($calendarId)->id, (string) $objectUri);

        return null === $event ? null : $this->describe($event);
    }

    public function getMultipleCalendarObjects($calendarId, array $uris): array
    {
        if ([] === $uris) {
            return [];
        }
        $events = Scheduler::events()->query()->where('calendarId', (int) $this->calendar($calendarId)->id)->whereIn('davUri', array_values($uris))->get();

        return array_map($this->describe(...), $events);
    }

    public function createCalendarObject($calendarId, $objectUri, $calendarData): string
    {
        $calendar = $this->calendar($calendarId, write: true);
        $event = new Event(timezone: $calendar->timezone);
        $event->calendarId = (int) $calendar->id;
        $event->davUri = (string) $objectUri;
        $event->published = Access::canPublish($this->context->user);

        return $this->store($event, $calendar, (string) $calendarData);
    }

    public function updateCalendarObject($calendarId, $objectUri, $calendarData): string
    {
        $calendar = $this->calendar($calendarId, write: true);
        $event = Scheduler::events()->findByDavUri((int) $calendar->id, (string) $objectUri) ?? throw new \Sabre\DAV\Exception\NotFound(I18n::t('event_not_found'));
        if (!Access::canEditEvent($event, $this->context->user)) {
            throw new Forbidden(I18n::t('dav_event_foreign'));
        }

        return $this->store($event, $calendar, (string) $calendarData);
    }

    public function deleteCalendarObject($calendarId, $objectUri): void
    {
        $calendar = $this->calendar($calendarId, write: true);
        $event = Scheduler::events()->findByDavUri((int) $calendar->id, (string) $objectUri);
        if (null !== $event) {
            if (!Access::canDeleteEvent($event, $this->context->user)) {
                throw new Forbidden(I18n::t('event_delete_denied'));
            }
            Scheduler::events()->delete($event);
        }
    }

    /**
     * Grenzt Zeitbereichs-Abfragen über den Vorkommens-Index ein, bevor sabre die Filter im Detail prüft.
     */
    public function calendarQuery($calendarId, array $filters): array
    {
        $range = $filters['comp-filters'][0]['time-range'] ?? null;
        if (!is_array($range) || (null === ($range['start'] ?? null) && null === ($range['end'] ?? null))) {
            return parent::calendarQuery($calendarId, $filters);
        }

        $query = Scheduler::occurrences()->includeUnpublished()->inCalendars((int) $this->calendar($calendarId)->id);
        if ($range['start'] instanceof \DateTimeInterface) {
            $query->from($range['start']);
        }
        if ($range['end'] instanceof \DateTimeInterface) {
            $query->until($range['end']);
        }

        $uris = [];
        foreach ($query->get() as $occurrence) {
            if (null !== $occurrence->event?->davUri) {
                $uris[$occurrence->event->davUri] = true;
            }
        }

        return array_keys($uris);
    }

    public function getChangesForCalendar($calendarId, $syncToken, $syncLevel, $limit = null): ?array
    {
        $calendar = $this->calendar($calendarId);
        $result = ['syncToken' => (string) $calendar->syncToken, 'added' => [], 'modified' => [], 'deleted' => []];

        if ('' === (string) $syncToken) {
            $result['added'] = array_column($this->getCalendarObjects($calendarId), 'uri');

            return $result;
        }
        if (!ctype_digit((string) $syncToken) || (int) $syncToken > $calendar->syncToken) {
            return null; // unbekannter Stand: der Client gleicht vollständig neu ab
        }

        $changes = Scheduler::em()->repository(DavChange::class)->query()
            ->where('calendarId', (int) $calendar->id)
            ->where('syncToken', '>', (int) $syncToken)
            ->orderBy('syncToken')
            ->get();

        // Je Ressource zählt der letzte Stand; angelegt und wieder gelöscht heißt: nie gesehen.
        $state = [];
        foreach ($changes as $change) {
            $first = $state[$change->uri]['first'] ?? $change->operation;
            $state[$change->uri] = ['first' => $first, 'last' => $change->operation];
        }
        foreach ($state as $uri => $operations) {
            if (DavChange::DELETED === $operations['last']) {
                if (DavChange::ADDED !== $operations['first']) {
                    $result['deleted'][] = (string) $uri;
                }
            } else {
                $result[DavChange::ADDED === $operations['first'] ? 'added' : 'modified'][] = (string) $uri;
            }
        }

        return $result;
    }

    private function store(Event $event, Calendar $calendar, string $calendarData): string
    {
        try {
            $parsed = $this->parser->parse($calendarData);
        } catch (InvalidIcalException $e) {
            throw new BadRequest($e->getMessage());
        }
        if (1 !== count($parsed)) {
            throw new BadRequest(I18n::t('dav_one_uid'));
        }

        $location = null !== $event->locationId ? Scheduler::locations()->find($event->locationId) : null;
        $this->parser->apply($parsed[0], $event, $this->clangId, $calendar->timezone, $location?->label);
        $event->calendarId = (int) $calendar->id;

        try {
            Scheduler::events()->save($event);
        } catch (ValidationException $e) {
            throw new BadRequest($e->getMessage());
        }

        // scheduler normalisiert die Daten (UNTIL, Zeitzonen). Ohne ETag holt der Client die gespeicherte Fassung neu ab.
        return '';
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(Event $event): array
    {
        $vcalendar = $this->serializer->createCalendar();
        if (!$event->allDay) {
            $this->timezones->add($vcalendar, $event->timezone, $event->dtstart->modify('-1 year'), new DateTimeImmutable('+5 years'));
        }
        $location = null !== $event->locationId ? Scheduler::locations()->find($event->locationId) : null;
        $this->serializer->addEvent($vcalendar, $event, $this->clangId, $location);
        $data = $vcalendar->serialize();

        return [
            'id' => (int) $event->id,
            'uri' => (string) $event->davUri,
            'etag' => '"' . $event->etag . '"',
            'lastmodified' => $event->updatedAt?->getTimestamp(),
            'calendarid' => $event->calendarId,
            'size' => strlen($data),
            'calendardata' => $data,
            'component' => 'vevent',
        ];
    }

    private function calendar(mixed $calendarId, bool $write = false): Calendar
    {
        $calendar = Scheduler::calendars()->find((int) $calendarId);
        if (null === $calendar || !$calendar->davEnabled || null === $this->context->user || !Access::canEditCalendar((int) $calendar->id, $this->context->user)) {
            throw new Forbidden(I18n::t('dav_calendar_denied'));
        }
        if ($write && !$this->context->canWrite) {
            throw new Forbidden(I18n::t('dav_read_only'));
        }

        return $calendar;
    }
}
