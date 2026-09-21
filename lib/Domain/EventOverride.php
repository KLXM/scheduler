<?php

declare(strict_types=1);

namespace KLXM\Scheduler\Domain;

use DateTimeImmutable;
use KLXM\Scheduler\Domain\Enum\EventStatus;
use KLXM\Scheduler\Orm\Attribute\Column;
use KLXM\Scheduler\Orm\Attribute\Id;
use KLXM\Scheduler\Orm\Attribute\Index;
use KLXM\Scheduler\Orm\Attribute\Table;
use KLXM\Scheduler\Orm\ColumnType;

/**
 * Abweichung eines einzelnen Vorkommens einer Serie (iCalendar: VEVENT mit RECURRENCE-ID).
 *
 * Ausgefallene Vorkommen sind keine Overrides, sondern EXDATE-Einträge am Termin.
 */
#[Table('scheduler_event_override')]
#[Index('event_recurrence', ['event_id', 'recurrence_id'], unique: true)]
class EventOverride
{
    #[Id, Column]
    public private(set) ?int $id = null;

    #[Column]
    public int $eventId = 0;

    #[Column(length: 64)]
    public string $timezone = 'Europe/Berlin';

    /** Ursprünglicher Beginn des ersetzten Vorkommens in der Zeitzone des Termins. */
    #[Column(type: ColumnType::LocalDateTime, timezoneProperty: 'timezone')]
    public DateTimeImmutable $recurrenceId;

    #[Column(type: ColumnType::LocalDateTime, timezoneProperty: 'timezone')]
    public DateTimeImmutable $dtstart;

    /** Exklusives Ende wie in iCalendar. */
    #[Column(type: ColumnType::LocalDateTime, timezoneProperty: 'timezone')]
    public DateTimeImmutable $dtend;

    #[Column]
    public bool $allDay = false;

    #[Column]
    public EventStatus $status = EventStatus::Confirmed;

    #[Column(length: 500)]
    public ?string $locationText = null;

    /** @var array<int, array{title?: string, teaser?: string, description?: string}> abweichende Texte je Sprach-ID */
    #[Column]
    public array $translations = [];

    #[Column(type: ColumnType::LongText)]
    public ?string $extraIcal = null;
}
