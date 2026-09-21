<?php

declare(strict_types=1);

namespace KLXM\Scheduler\Domain\Enum;

/** Entspricht der iCalendar-Eigenschaft CLASS. */
enum Visibility: string
{
    case Public = 'PUBLIC';
    case Private = 'PRIVATE';
    case Confidential = 'CONFIDENTIAL';

    public static function fromIcal(?string $value): self
    {
        return self::tryFrom(strtoupper((string) $value)) ?? self::Public;
    }
}
