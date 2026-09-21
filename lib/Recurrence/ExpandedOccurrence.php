<?php

declare(strict_types=1);

namespace KLXM\Scheduler\Recurrence;

use DateTimeImmutable;

/**
 * Ein berechnetes Vorkommen in der Zeitzone des Termins. Das Ende ist exklusiv.
 */
final readonly class ExpandedOccurrence
{
    public function __construct(
        public DateTimeImmutable $start,
        public DateTimeImmutable $end,
        public bool $allDay,
        public string $recurrenceKey,
        public bool $isOverride,
    ) {}
}
