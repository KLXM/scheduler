<?php

declare(strict_types=1);

namespace KLXM\Scheduler\Domain;

use DateTimeImmutable;
use KLXM\Scheduler\Orm\Attribute\Column;
use KLXM\Scheduler\Orm\Attribute\Id;
use KLXM\Scheduler\Orm\Attribute\Index;
use KLXM\Scheduler\Orm\Attribute\Table;
use KLXM\Scheduler\Orm\ColumnType;
use KLXM\Scheduler\Orm\Timestamped;
use KLXM\Scheduler\Orm\Timestamps;

/**
 * Abonnierter fremder Kalender: eine ICS-Adresse, die regelmäßig in einen Kalender abgeglichen wird.
 */
#[Table('scheduler_subscription')]
#[Index('calendar', ['calendar_id'])]
class Subscription implements Timestamped
{
    use Timestamps;

    #[Id, Column]
    public private(set) ?int $id = null;

    #[Column]
    public int $calendarId = 0;

    #[Column(length: 1000)]
    public string $url = '';

    #[Column]
    public int $clangId = 1;

    /** Termine löschen, die in der Quelle nicht mehr vorkommen. */
    #[Column]
    public bool $removeMissing = true;

    /** Frühestens nach so vielen Minuten wird erneut abgeglichen. */
    #[Column]
    public int $intervalMinutes = 1440;

    #[Column]
    public bool $active = true;

    #[Column]
    public ?DateTimeImmutable $lastSyncAt = null;

    /** "ok", "error" oder leer, solange noch nie abgeglichen wurde. */
    #[Column(length: 16)]
    public string $lastStatus = '';

    #[Column(type: ColumnType::Text)]
    public ?string $lastMessage = null;

    public function isDue(DateTimeImmutable $now): bool
    {
        return $this->active && (null === $this->lastSyncAt || $this->lastSyncAt->modify('+' . max(5, $this->intervalMinutes) . ' minutes') <= $now);
    }
}
