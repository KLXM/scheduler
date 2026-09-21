<?php

declare(strict_types=1);

namespace KLXM\Scheduler\Dav;

use KLXM\Dav\Context;
use KLXM\Dav\Provider;
use KLXM\Scheduler\Domain\Calendar;
use KLXM\Scheduler\I18n;
use KLXM\Scheduler\Security\Access;
use rex_clang;
use rex_user;
use Sabre\CalDAV;
use Sabre\DAVACL\PrincipalBackend\BackendInterface as PrincipalBackendInterface;

/**
 * Hängt die scheduler-Kalender als CalDAV in den Server des Addons dav ein.
 * Anmeldung, App-Passwörter und Server-Adresse gehören dem dav-Addon.
 */
final class CalendarProvider implements Provider
{
    public function key(): string
    {
        return 'scheduler';
    }

    public function label(): string
    {
        return I18n::t('dav_provider_label');
    }

    public function protocol(): string
    {
        return 'caldav';
    }

    public function isAvailableFor(rex_user $user): bool
    {
        return Access::canUse($user);
    }

    public function describe(rex_user $user): array
    {
        return array_map(static fn (Calendar $calendar): string => $calendar->name, Access::davCalendars($user));
    }

    public function nodes(Context $context, PrincipalBackendInterface $principals): array
    {
        return [new CalDAV\CalendarRoot($principals, new CalendarBackend($context, rex_clang::getStartId()))];
    }

    public function plugins(Context $context): array
    {
        return [new CalDAV\Plugin()];
    }
}
