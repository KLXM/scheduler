<?php

declare(strict_types=1);

namespace KLXM\Scheduler\Security;

use KLXM\Scheduler\Scheduler;
use rex_complex_perm;
use rex_i18n;

/**
 * Rollenrecht "Kalender": legt fest, in welchen Kalendern eine Rolle Termine pflegen darf.
 */
final class CalendarPerm extends rex_complex_perm
{
    public const string KEY = 'scheduler_calendars';

    public function hasCalendar(int $calendarId): bool
    {
        return $this->hasAll() || in_array($calendarId, array_map(intval(...), $this->perms), true);
    }

    /**
     * @return array<string, mixed>
     */
    public static function getFieldParams(): array
    {
        $options = [];
        foreach (Scheduler::calendars()->all() as $calendar) {
            $options[(int) $calendar->id] = $calendar->name;
        }

        return [
            'label' => rex_i18n::msg('scheduler_perm_calendars'),
            'all_label' => rex_i18n::msg('scheduler_perm_calendars_all'),
            'options' => $options,
        ];
    }
}
