<?php

declare(strict_types=1);

namespace KLXM\Scheduler\Ical;

use Sabre\VObject\Component\VEvent;

/**
 * Alle VEVENT-Komponenten einer UID: der Stammtermin und seine Einzelausnahmen.
 */
final readonly class ParsedEvent
{
    /**
     * @param list<VEvent> $overrides
     */
    public function __construct(
        public string $uid,
        public VEvent $master,
        public array $overrides,
    ) {}
}
