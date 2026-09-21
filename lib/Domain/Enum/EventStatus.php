<?php

declare(strict_types=1);

namespace KLXM\Scheduler\Domain\Enum;

/** Entspricht der iCalendar-Eigenschaft STATUS. */
enum EventStatus: string
{
    case Confirmed = 'CONFIRMED';
    case Tentative = 'TENTATIVE';
    case Cancelled = 'CANCELLED';

    public static function fromIcal(?string $value): self
    {
        return self::tryFrom(strtoupper((string) $value)) ?? self::Confirmed;
    }
}
