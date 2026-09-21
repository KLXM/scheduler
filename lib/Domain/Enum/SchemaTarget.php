<?php

declare(strict_types=1);

namespace KLXM\Scheduler\Domain\Enum;

enum SchemaTarget: string
{
    case Event = 'event';
    case Calendar = 'calendar';
    case Location = 'location';
}
