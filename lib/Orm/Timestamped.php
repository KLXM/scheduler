<?php

declare(strict_types=1);

namespace KLXM\Scheduler\Orm;

/**
 * Entities mit diesem Interface bekommen Erstell- und Änderungsdaten vom Repository gesetzt.
 */
interface Timestamped
{
    public function touch(\DateTimeImmutable $now, string $user, bool $isNew): void;
}
