<?php

declare(strict_types=1);

namespace KLXM\Scheduler\Domain;

use KLXM\Scheduler\Orm\Attribute\Column;
use KLXM\Scheduler\Orm\Attribute\Id;
use KLXM\Scheduler\Orm\Attribute\Index;
use KLXM\Scheduler\Orm\Attribute\Table;
use KLXM\Scheduler\Orm\ColumnType;
use KLXM\Scheduler\Orm\Timestamped;
use KLXM\Scheduler\Orm\Timestamps;

/**
 * Ein Kalender bündelt Termine und entspricht einer CalDAV-Collection.
 */
#[Table('scheduler_calendar')]
#[Index('slug', ['slug'], unique: true)]
class Calendar implements Timestamped
{
    use Timestamps;

    #[Id, Column]
    public private(set) ?int $id = null;

    #[Column]
    public string $name = '';

    /** @var array<int, string> Namen je Sprach-ID, soweit sie vom Standardnamen abweichen */
    #[Column]
    public array $nameTranslations = [];

    /** URL-Bestandteil für Feeds und CalDAV. */
    #[Column(length: 100)]
    public string $slug = '';

    #[Column(length: 32)]
    public string $color = '#3788d8';

    #[Column(length: 64)]
    public string $timezone = 'Europe/Berlin';

    #[Column(type: ColumnType::Text)]
    public ?string $description = null;

    /** Sichtbar im Frontend und in Abfragen. */
    #[Column]
    public bool $active = true;

    /** Öffentlicher ICS-Feed ohne Anmeldung. */
    #[Column]
    public bool $publicFeed = false;

    /** Über Kalender-Apps (CalDAV) anbieten. Aus für Kalender, die nur der Website dienen, etwa importierte Ferien. */
    #[Column]
    public bool $davEnabled = true;

    #[Column]
    public int $priority = 0;

    /** Zähler für CalDAV-Synchronisation, steigt bei jeder Terminänderung. */
    #[Column]
    public int $syncToken = 1;

    /** @var array<string, mixed> */
    #[Column]
    public array $custom = [];

    public function name(?int $clangId = null): string
    {
        $translated = null !== $clangId ? ($this->nameTranslations[$clangId] ?? '') : '';

        return '' !== $translated ? $translated : $this->name;
    }
}
