<?php

declare(strict_types=1);

namespace KLXM\Scheduler\Security;

use KLXM\Scheduler\Domain\Calendar;
use KLXM\Scheduler\Domain\Event;
use KLXM\Scheduler\Scheduler;
use rex;
use rex_user;

/**
 * Beantwortet, was der angemeldete Backend-Benutzer in scheduler darf.
 */
final class Access
{
    public const string PERM_EDIT_FOREIGN = 'scheduler[edit_foreign]';
    public const string PERM_PUBLISH = 'scheduler[publish]';
    public const string PERM_DELETE = 'scheduler[delete]';

    public static function user(): ?rex_user
    {
        return rex::getUser();
    }

    public static function canUse(?rex_user $user = null): bool
    {
        $user ??= self::user();

        return null !== $user && ($user->isAdmin() || $user->hasPerm('scheduler[]'));
    }

    public static function canEditCalendar(int $calendarId, ?rex_user $user = null): bool
    {
        $user ??= self::user();
        if (null === $user || !self::canUse($user)) {
            return false;
        }
        if ($user->isAdmin()) {
            return true;
        }
        $perm = $user->getComplexPerm(CalendarPerm::KEY);

        return $perm instanceof CalendarPerm && $perm->hasCalendar($calendarId);
    }

    /**
     * Darf der Benutzer diesen Termin ändern? Ohne das Recht "fremde Termine bearbeiten" nur eigene.
     */
    public static function canEditEvent(Event $event, ?rex_user $user = null): bool
    {
        $user ??= self::user();
        if (null === $user || !self::canEditCalendar($event->calendarId, $user)) {
            return false;
        }

        return null === $event->id
            || self::has($user, self::PERM_EDIT_FOREIGN)
            || 0 === strcasecmp((string) $event->createdBy, $user->getLogin());
    }

    public static function canDeleteEvent(Event $event, ?rex_user $user = null): bool
    {
        $user ??= self::user();

        return null !== $user && self::canEditEvent($event, $user) && self::has($user, self::PERM_DELETE);
    }

    /**
     * Darf der Benutzer Termine online stellen? Ohne das Recht bleiben neue Termine offline,
     * bis jemand mit Freigaberecht sie veröffentlicht.
     */
    public static function canPublish(?rex_user $user = null): bool
    {
        $user ??= self::user();

        return null !== $user && self::has($user, self::PERM_PUBLISH);
    }

    public static function canManageLocations(?rex_user $user = null): bool
    {
        $user ??= self::user();

        return null !== $user && self::canUse($user) && self::has($user, 'scheduler[locations]');
    }

    public static function canManageCalendars(?rex_user $user = null): bool
    {
        $user ??= self::user();

        return null !== $user && self::canUse($user) && self::has($user, 'scheduler[calendars]');
    }

    private static function has(rex_user $user, string $perm): bool
    {
        return $user->isAdmin() || $user->hasPerm($perm);
    }

    /**
     * @return list<Calendar> Kalender, in denen der Benutzer Termine pflegen darf
     */
    public static function editableCalendars(?rex_user $user = null): array
    {
        return array_values(array_filter(
            Scheduler::calendars()->all(),
            static fn (Calendar $calendar): bool => self::canEditCalendar((int) $calendar->id, $user),
        ));
    }

    /**
     * Kalender, die der Benutzer in Kalender-Apps sieht: die er pflegen darf und die für Apps freigegeben sind.
     *
     * @return list<Calendar>
     */
    public static function davCalendars(?rex_user $user = null): array
    {
        return array_values(array_filter(self::editableCalendars($user), static fn (Calendar $calendar): bool => $calendar->davEnabled));
    }

    /**
     * @return list<int>
     */
    public static function editableCalendarIds(): array
    {
        return array_map(static fn (Calendar $calendar): int => (int) $calendar->id, self::editableCalendars());
    }
}
