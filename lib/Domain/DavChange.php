<?php

declare(strict_types=1);

namespace KLXM\Scheduler\Domain;

use KLXM\Scheduler\Orm\Attribute\Column;
use KLXM\Scheduler\Orm\Attribute\Id;
use KLXM\Scheduler\Orm\Attribute\Index;
use KLXM\Scheduler\Orm\Attribute\Table;

/**
 * Änderungsprotokoll je Kalender. Damit holen CalDAV-Clients nur, was sich seit ihrem letzten
 * Abgleich geändert hat (RFC 6578, sync-collection).
 */
#[Table('scheduler_dav_change')]
#[Index('calendar_token', ['calendar_id', 'sync_token'])]
class DavChange
{
    public const int ADDED = 1;
    public const int MODIFIED = 2;
    public const int DELETED = 3;

    #[Id, Column]
    public private(set) ?int $id = null;

    #[Column]
    public int $calendarId = 0;

    #[Column(length: 255)]
    public string $uri = '';

    #[Column]
    public int $syncToken = 0;

    #[Column]
    public int $operation = self::MODIFIED;
}
