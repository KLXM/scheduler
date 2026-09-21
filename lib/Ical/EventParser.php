<?php

declare(strict_types=1);

namespace KLXM\Scheduler\Ical;

use KLXM\Scheduler\I18n;
use DateTimeImmutable;
use DateTimeZone;
use KLXM\Scheduler\Domain\Enum\EventStatus;
use KLXM\Scheduler\Domain\Enum\Transparency;
use KLXM\Scheduler\Domain\Enum\Visibility;
use KLXM\Scheduler\Domain\Event;
use KLXM\Scheduler\Domain\EventOverride;
use Sabre\VObject\Component;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\Property;
use Sabre\VObject\Reader;

/**
 * Liest iCalendar-Daten und überträgt sie auf Termine. Gegenstück zum EventSerializer.
 */
final class EventParser
{
    /** Eigenschaften, die scheduler selbst modelliert. Alles andere wandert in Event::$extraIcal. */
    private const array MODELLED = [
        'UID', 'DTSTART', 'DTEND', 'DURATION', 'RRULE', 'EXDATE', 'RDATE', 'RECURRENCE-ID',
        'SUMMARY', 'DESCRIPTION', 'LOCATION', 'URL', 'STATUS', 'CLASS', 'TRANSP', 'CATEGORIES',
        'ORGANIZER', 'SEQUENCE', 'DTSTAMP', 'CREATED', 'LAST-MODIFIED',
    ];

    /**
     * @return list<ParsedEvent>
     * @throws InvalidIcalException
     */
    public function parse(string $ics): array
    {
        try {
            $document = Reader::read($ics, Reader::OPTION_FORGIVING);
        } catch (\Throwable $e) {
            throw new InvalidIcalException(I18n::t('ical_unreadable', $e->getMessage()), previous: $e);
        }
        if (!$document instanceof VCalendar) {
            throw new InvalidIcalException(I18n::t('ical_no_vcalendar'));
        }

        /** @var array<string, array{master: ?VEvent, overrides: list<VEvent>}> $grouped */
        $grouped = [];
        foreach ($document->select('VEVENT') as $vevent) {
            if (!$vevent instanceof VEvent || null === $vevent->DTSTART) {
                continue;
            }
            $uid = trim((string) $vevent->UID);
            if ('' === $uid) {
                $uid = 'scheduler-' . sha1($vevent->serialize());
            }
            $grouped[$uid] ??= ['master' => null, 'overrides' => []];
            if (null === $vevent->{'RECURRENCE-ID'}) {
                $grouped[$uid]['master'] = $vevent;
            } else {
                $grouped[$uid]['overrides'][] = $vevent;
            }
        }

        $result = [];
        foreach ($grouped as $uid => $group) {
            // Liegt nur eine Ausnahme ohne Stammtermin vor, wird sie als eigenständiger Termin behandelt.
            $master = $group['master'] ?? array_shift($group['overrides']);
            if (null !== $master) {
                $result[] = new ParsedEvent((string) $uid, $master, $group['overrides']);
            }
        }

        return $result;
    }

    /**
     * Überträgt die gelesenen Daten auf einen neuen oder bestehenden Termin.
     *
     * @param string|null $currentLocationLabel Adresszeile der aktuell zugeordneten Location; bleibt
     *                                          LOCATION unverändert, bleibt auch die Zuordnung erhalten
     */
    public function apply(ParsedEvent $parsed, Event $event, int $clangId, string $defaultTimezone, ?string $currentLocationLabel = null): void
    {
        $master = $parsed->master;
        $event->uid = $parsed->uid;

        [$start, $end, $allDay, $timezone] = $this->period($master, $defaultTimezone);
        $event->schedule($start, $end, $allDay, $timezone);
        $zone = new DateTimeZone($event->timezone);

        $event->rrule = null !== $master->RRULE ? $this->rruleString($master->RRULE) : null;
        $event->exdates = $this->keys($master, 'EXDATE', $event, $zone);
        $event->rdates = $this->keys($master, 'RDATE', $event, $zone);

        $event->status = EventStatus::fromIcal($this->text($master, 'STATUS'));
        $event->visibility = Visibility::fromIcal($this->text($master, 'CLASS'));
        $event->transparency = Transparency::fromIcal($this->text($master, 'TRANSP'));
        $event->url = $this->text($master, 'URL');
        $event->organizer = $this->text($master, 'ORGANIZER');
        $event->categories = null !== $master->CATEGORIES ? array_values(array_filter(array_map(trim(...), $master->CATEGORIES->getParts()))) : [];
        $event->extraIcal = $this->extra($master);

        $location = $this->text($master, 'LOCATION');
        if ($location !== $currentLocationLabel) {
            $event->locationId = null;
            $event->locationText = $location;
        }

        $translation = $event->translate($clangId);
        $translation->title = $this->text($master, 'SUMMARY') ?? '';
        $this->applyDescription($translation, $this->text($master, 'DESCRIPTION'));

        $event->overrides = [];
        foreach ($parsed->overrides as $vevent) {
            $override = $this->override($vevent, $event, $clangId, $zone);
            $event->overrides[$event->recurrenceKey($override->recurrenceId)] = $override;
        }
    }

    private function override(VEvent $vevent, Event $event, int $clangId, DateTimeZone $zone): EventOverride
    {
        [$start, $end, $allDay] = $this->period($vevent, $event->timezone);

        $override = new EventOverride();
        $override->timezone = $event->timezone;
        $override->recurrenceId = $this->local($vevent->{'RECURRENCE-ID'}->getDateTime($zone), $event->allDay, $zone);
        $override->dtstart = $this->local($start, $allDay, $zone);
        $override->dtend = $this->local($end, $allDay, $zone);
        $override->allDay = $allDay;
        $override->status = EventStatus::fromIcal($this->text($vevent, 'STATUS'));
        $override->locationText = $this->text($vevent, 'LOCATION');
        $override->extraIcal = $this->extra($vevent);

        $texts = array_filter([
            'title' => $this->text($vevent, 'SUMMARY'),
            'description' => $this->text($vevent, 'DESCRIPTION'),
        ], static fn (?string $value): bool => null !== $value);
        // Texte, die dem Stammtermin entsprechen, sind keine Abweichung.
        if (($texts['title'] ?? null) === $event->title($clangId)) {
            unset($texts['title']);
        }
        if (($texts['description'] ?? null) === ($event->translation($clangId)->plainDescription ?? '')) {
            unset($texts['description']);
        }
        if ([] !== $texts) {
            $override->translations[$clangId] = $texts;
        }

        return $override;
    }

    /**
     * @return array{DateTimeImmutable, DateTimeImmutable, bool, string} Beginn, exklusives Ende, ganztägig, Zeitzone
     */
    private function period(VEvent $vevent, string $defaultTimezone): array
    {
        $defaultZone = new DateTimeZone($defaultTimezone);
        $allDay = !$vevent->DTSTART->hasTime();
        $start = DateTimeImmutable::createFromInterface($vevent->DTSTART->getDateTime($defaultZone));

        $timezone = $vevent->DTSTART->isFloating() ? $defaultTimezone : $start->getTimezone()->getName();
        if ($allDay || in_array($timezone, ['Z', '+00:00'], true)) {
            $timezone = $allDay ? $defaultTimezone : 'UTC';
        }

        $end = match (true) {
            null !== $vevent->DTEND => DateTimeImmutable::createFromInterface($vevent->DTEND->getDateTime($defaultZone)),
            null !== $vevent->DURATION => $start->add($vevent->DURATION->getDateInterval()),
            default => $allDay ? $start->modify('+1 day') : $start,
        };

        return [$start, $end, $allDay, $timezone];
    }

    /**
     * @return list<string>
     */
    private function keys(VEvent $vevent, string $property, Event $event, DateTimeZone $zone): array
    {
        $keys = [];
        foreach ($vevent->select($property) as $node) {
            if (!$node instanceof Property\ICalendar\DateTime) {
                continue;
            }
            foreach ($node->getDateTimes($zone) as $dateTime) {
                $keys[] = $event->recurrenceKey($this->local($dateTime, $event->allDay, $zone));
            }
        }
        $keys = array_values(array_unique($keys));
        sort($keys);

        return $keys;
    }

    private function rruleString(Property $rrule): string
    {
        return (string) $rrule->getValue();
    }

    private function local(\DateTimeInterface $value, bool $allDay, DateTimeZone $zone): DateTimeImmutable
    {
        $value = DateTimeImmutable::createFromInterface($value);

        return $allDay ? new DateTimeImmutable($value->format('Y-m-d') . ' 00:00:00', $zone) : $value->setTimezone($zone);
    }

    private function text(VEvent $vevent, string $property): ?string
    {
        $node = $vevent->{$property};
        $value = null === $node ? '' : trim((string) $node);

        return '' === $value ? null : $value;
    }

    /**
     * Schützt gepflegten Rich-Text: nur wenn sich der reine Text geändert hat, wird er ersetzt.
     */
    private function applyDescription(\KLXM\Scheduler\Domain\EventTranslation $translation, ?string $plain): void
    {
        $plain ??= '';
        if ($plain === $translation->plainDescription) {
            return;
        }

        $translation->teaser = null;
        $translation->description = '' === $plain
            ? null
            : '<p>' . str_replace("\n", '<br>', htmlspecialchars($plain, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) . '</p>';
    }

    /**
     * Serialisiert alle nicht modellierten Eigenschaften und Unterkomponenten als VEVENT-Fragment.
     */
    private function extra(VEvent $vevent): ?string
    {
        $lines = '';
        foreach ($vevent->children() as $child) {
            if ($child instanceof Component || !in_array(strtoupper($child->name), self::MODELLED, true)) {
                $lines .= $child->serialize();
            }
        }

        return '' === $lines ? null : "BEGIN:VEVENT\r\n" . $lines . "END:VEVENT";
    }
}
