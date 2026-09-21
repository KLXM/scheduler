<?php

declare(strict_types=1);

namespace KLXM\Scheduler\Recurrence;

/** Worauf sich eine Änderung an einem Serientermin bezieht. */
enum EditScope: string
{
    case This = 'this';
    case Following = 'following';
    case All = 'all';
}
