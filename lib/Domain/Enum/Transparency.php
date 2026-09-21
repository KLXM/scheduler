<?php

declare(strict_types=1);

namespace KLXM\Scheduler\Domain\Enum;

/** Entspricht der iCalendar-Eigenschaft TRANSP: blockiert der Termin Zeit oder nicht. */
enum Transparency: string
{
    case Opaque = 'OPAQUE';
    case Transparent = 'TRANSPARENT';

    public static function fromIcal(?string $value): self
    {
        return self::tryFrom(strtoupper((string) $value)) ?? self::Opaque;
    }
}
