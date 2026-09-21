<?php

declare(strict_types=1);

namespace KLXM\Scheduler\Domain;

use DateTimeImmutable;
use DateTimeZone;
use KLXM\Scheduler\Domain\Enum\EventStatus;
use KLXM\Scheduler\Domain\Enum\Transparency;
use KLXM\Scheduler\Domain\Enum\Visibility;
use KLXM\Scheduler\Orm\Attribute\Column;
use KLXM\Scheduler\Orm\Attribute\Id;
use KLXM\Scheduler\Orm\Attribute\Index;
use KLXM\Scheduler\Orm\Attribute\Table;
use KLXM\Scheduler\Orm\ColumnType;
use KLXM\Scheduler\Orm\Timestamped;
use KLXM\Scheduler\Orm\Timestamps;

/**
 * Ein Termin oder eine Terminserie. Die festen Spalten bilden die iCalendar-Eigenschaften
 * eines VEVENT ab; alles Projektspezifische liegt in $custom.
 */
#[Table('scheduler_event')]
#[Index('uid', ['uid'], unique: true)]
#[Index('calendar', ['calendar_id'])]
#[Index('import', ['import_source', 'import_ref'])]
#[Index('dav', ['calendar_id', 'dav_uri'])]
class Event implements Timestamped
{
    use Timestamps;

    #[Id, Column]
    public private(set) ?int $id = null;

    /** Weltweit eindeutige iCalendar-UID. */
    #[Column(length: 255)]
    public string $uid = '';

    #[Column]
    public int $calendarId = 0;

    /** Dateiname des Termins in der CalDAV-Collection. Clients vergeben ihn beim Anlegen selbst. */
    #[Column(length: 255)]
    public ?string $davUri = null;

    #[Column]
    public ?int $locationId = null;

    /** Freitext-Ort, wenn keine gepflegte Location zugeordnet ist (etwa aus CalDAV-Clients). */
    #[Column(length: 500)]
    public ?string $locationText = null;

    #[Column(length: 64)]
    public private(set) string $timezone = 'Europe/Berlin';

    #[Column(type: ColumnType::LocalDateTime, timezoneProperty: 'timezone')]
    public private(set) DateTimeImmutable $dtstart;

    /** Exklusives Ende wie in iCalendar: bei ganztägigen Terminen der Tag nach dem letzten Tag. */
    #[Column(type: ColumnType::LocalDateTime, timezoneProperty: 'timezone')]
    public private(set) DateTimeImmutable $dtend;

    #[Column]
    public private(set) bool $allDay = false;

    /** RFC-5545-Regel ohne "RRULE:"-Präfix, etwa FREQ=WEEKLY;BYDAY=MO,WE. */
    #[Column(type: ColumnType::Text)]
    public ?string $rrule = null {
        set(?string $value) {
            $value = null === $value ? '' : trim($value);
            if (str_starts_with(strtoupper($value), 'RRULE:')) {
                $value = substr($value, 6);
            }
            $this->rrule = '' === $value ? null : strtoupper($value);
        }
    }

    /** @var list<string> ausgefallene Vorkommen als Ymd oder Ymd\THis in Terminzeitzone */
    #[Column]
    public array $exdates = [];

    /** @var list<string> zusätzliche Vorkommen als Ymd oder Ymd\THis in Terminzeitzone */
    #[Column]
    public array $rdates = [];

    #[Column(length: 32)]
    public EventStatus $status = EventStatus::Confirmed;

    #[Column(length: 32)]
    public Visibility $visibility = Visibility::Public;

    #[Column(length: 32)]
    public Transparency $transparency = Transparency::Opaque;

    #[Column(length: 1000)]
    public ?string $url = null;

    #[Column(length: 500)]
    public ?string $organizer = null;

    /** @var list<string> Schlagwörter, iCalendar: CATEGORIES */
    #[Column]
    public array $categories = [];

    /** Online/offline in REDAXO, unabhängig vom iCalendar-STATUS. */
    #[Column]
    public bool $published = true;

    #[Column]
    public int $sequence = 0;

    #[Column(length: 64)]
    public string $etag = '';

    /** Nicht modellierte iCalendar-Eigenschaften und Komponenten (VALARM, ATTENDEE, X-...). */
    #[Column(type: ColumnType::LongText)]
    public ?string $extraIcal = null;

    /** @var array<string, mixed> Werte der Custom Fields */
    #[Column]
    public array $custom = [];

    /** Bis zu diesem Zeitpunkt sind Vorkommen im Index berechnet. */
    #[Column]
    public ?DateTimeImmutable $indexedUntil = null;

    #[Column(length: 64)]
    public ?string $importSource = null;

    #[Column(length: 255)]
    public ?string $importRef = null;

    /** @var array<int, EventTranslation> nach Sprach-ID; vom Repository geladen und gespeichert */
    public array $translations = [];

    /** @var array<string, EventOverride> nach Recurrence-Key; vom Repository geladen und gespeichert */
    public array $overrides = [];

    public function __construct(?DateTimeImmutable $start = null, ?DateTimeImmutable $end = null, bool $allDay = false, string $timezone = 'Europe/Berlin')
    {
        $start ??= new DateTimeImmutable('today 09:00', new DateTimeZone($timezone));
        $this->schedule($start, $end ?? $start->modify($allDay ? '+1 day' : '+1 hour'), $allDay, $timezone);
    }

    public bool $isRecurring {
        get => null !== $this->rrule || [] !== $this->rdates;
    }

    /** Letzter Kalendertag (inklusiv). Für ganztägige Termine der Tag vor dem exklusiven Ende. */
    public DateTimeImmutable $lastDay {
        get => $this->allDay ? $this->dtend->modify('-1 day') : $this->dtend;
    }

    /** Dauer in Sekunden zwischen Beginn und exklusivem Ende. */
    public int $duration {
        get => $this->dtend->getTimestamp() - $this->dtstart->getTimestamp();
    }

    /**
     * Setzt Zeitraum und Zeitzone gemeinsam, damit die Wanduhrzeiten konsistent bleiben.
     *
     * @param DateTimeImmutable $end exklusives Ende; bei ganztägigen Terminen der Tag nach dem letzten Tag
     */
    public function schedule(DateTimeImmutable $start, DateTimeImmutable $end, bool $allDay = false, ?string $timezone = null): static
    {
        $zone = new DateTimeZone($timezone ?? $this->timezone);

        if ($allDay) {
            // Ganztägige Termine sind reine Kalendertage: Datum übernehmen, nicht umrechnen.
            $start = new DateTimeImmutable($start->format('Y-m-d') . ' 00:00:00', $zone);
            $end = new DateTimeImmutable($end->format('Y-m-d') . ' 00:00:00', $zone);
            if ($end <= $start) {
                $end = $start->modify('+1 day');
            }
        } else {
            $start = $start->setTimezone($zone);
            $end = $end->setTimezone($zone);
            if ($end < $start) {
                $end = $start;
            }
        }

        $this->timezone = $zone->getName();
        $this->dtstart = $start;
        $this->dtend = $end;
        $this->allDay = $allDay;

        return $this;
    }

    /**
     * Ganztägiger Termin über inklusive Kalendertage, wie ihn Menschen eingeben.
     */
    public function scheduleAllDay(DateTimeImmutable $firstDay, ?DateTimeImmutable $lastDay = null, ?string $timezone = null): static
    {
        return $this->schedule($firstDay, ($lastDay ?? $firstDay)->modify('+1 day'), true, $timezone);
    }

    public function translation(?int $clangId = null, bool $fallback = true): ?EventTranslation
    {
        if (null !== $clangId && isset($this->translations[$clangId])) {
            return $this->translations[$clangId];
        }
        if (!$fallback && null !== $clangId) {
            return null;
        }

        return $this->translations[array_key_first($this->translations) ?? 0] ?? null;
    }

    public function translate(int $clangId): EventTranslation
    {
        return $this->translations[$clangId] ??= new EventTranslation($clangId);
    }

    public function title(?int $clangId = null): string
    {
        $own = $this->translation($clangId, false)?->title;

        return (null !== $own && '' !== $own) ? $own : ($this->translation()->title ?? '');
    }

    /**
     * @param string $key Ymd oder Ymd\THis in Terminzeitzone
     */
    public function exclude(string $key): static
    {
        if (!in_array($key, $this->exdates, true)) {
            $this->exdates[] = $key;
            sort($this->exdates);
        }
        unset($this->overrides[$key]);

        return $this;
    }

    /** Schlüssel eines Vorkommens innerhalb dieser Serie. */
    public function recurrenceKey(DateTimeImmutable $start): string
    {
        return $this->allDay
            ? $start->format('Ymd')
            : $start->setTimezone(new DateTimeZone($this->timezone))->format('Ymd\THis');
    }

    public function custom(string $field, mixed $default = null): mixed
    {
        return $this->custom[$field] ?? $default;
    }
}
