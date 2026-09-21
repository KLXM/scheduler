<?php

declare(strict_types=1);

namespace KLXM\Scheduler\Domain;

use DateTimeImmutable;
use KLXM\Scheduler\Orm\Attribute\Column;
use KLXM\Scheduler\Orm\Attribute\Id;
use KLXM\Scheduler\Orm\Attribute\Index;
use KLXM\Scheduler\Orm\Attribute\Table;
use KLXM\Scheduler\Orm\ColumnType;

/**
 * Vorberechnetes Vorkommen eines Termins. Die Tabelle ist ein Index und jederzeit neu aufbaubar.
 */
#[Table('scheduler_occurrence')]
#[Index('range_start', ['start_utc'])]
#[Index('range_end', ['end_utc'])]
#[Index('event', ['event_id'])]
#[Index('calendar_start', ['calendar_id', 'start_utc'])]
class Occurrence
{
    #[Id, Column]
    public private(set) ?int $id = null;

    #[Column]
    public int $eventId = 0;

    #[Column]
    public int $calendarId = 0;

    #[Column(length: 64)]
    public string $timezone = 'Europe/Berlin';

    #[Column]
    public DateTimeImmutable $startUtc;

    /** Exklusives Ende. */
    #[Column]
    public DateTimeImmutable $endUtc;

    #[Column(type: ColumnType::LocalDateTime, timezoneProperty: 'timezone')]
    public DateTimeImmutable $start;

    /** Exklusives Ende in der Zeitzone des Termins. */
    #[Column(type: ColumnType::LocalDateTime, timezoneProperty: 'timezone')]
    public DateTimeImmutable $end;

    #[Column]
    public bool $allDay = false;

    /** Schlüssel des Vorkommens innerhalb der Serie: Ymd oder Ymd\THis in Terminzeitzone. */
    #[Column(length: 20)]
    public string $recurrenceKey = '';

    #[Column]
    public bool $isOverride = false;

    /** Wird vom OccurrenceQuery gesetzt, keine Spalte. */
    public ?Event $event = null;

    public ?EventOverride $override = null;

    /** Letzter Kalendertag eines ganztägigen Vorkommens (inklusiv). */
    public DateTimeImmutable $lastDay {
        get => $this->allDay ? $this->end->modify('-1 day') : $this->end;
    }

    public bool $isMultiDay {
        get => $this->start->format('Y-m-d') !== $this->lastDay->format('Y-m-d');
    }

    public function title(?int $clangId = null): string
    {
        $overridden = null !== $clangId ? ($this->override?->translations[$clangId]['title'] ?? null) : null;

        return $overridden ?? $this->event?->title($clangId) ?? '';
    }
}
