<?php

declare(strict_types=1);

use KLXM\Scheduler\Backend\Html;
use KLXM\Scheduler\I18n;
use KLXM\Scheduler\Security\Access;

/**
 * Kalender-Apps: erklärt, was CalDAV bringt, zeigt die erreichbaren Kalender und führt direkt auf dieser
 * Seite durch das Verbinden. Server, Anmeldung und App-Passwörter stellt das Addon dav; dessen Assistent
 * ist hier eingebettet, damit niemand das Addon wechseln muss.
 */
$user = rex::requireUser();
$dav = rex_addon::get('dav');

// ---------- Was und wofür ----------
echo Html::section(I18n::e('caldav_intro_title'), '<p class="scheduler-lead">' . I18n::e('caldav_intro') . '</p>'
    . '<ul class="scheduler-benefits"><li>' . I18n::e('caldav_benefit_sync') . '</li><li>' . I18n::e('caldav_benefit_rights') . '</li><li>' . I18n::e('caldav_benefit_password') . '</li></ul>', class: 'info');

// ---------- Welche Kalender ----------
$rows = '';
foreach (Access::editableCalendars($user) as $calendar) {
    $rows .= '<tr><td class="rex-table-icon">' . Html::colorDot($calendar->color) . '</td><td>' . Html::e($calendar->name) . '</td><td>'
        . ($calendar->davEnabled
            ? '<span class="rex-online"><i class="rex-icon fa-check"></i> ' . I18n::e('caldav_calendar_available') . '</span>'
            : '<span class="text-muted"><i class="rex-icon fa-ban"></i> ' . I18n::e('caldav_calendar_excluded') . '</span>')
        . '</td></tr>';
}
echo Html::section(
    I18n::e('caldav_calendars_title'),
    '<p>' . I18n::e('caldav_calendars_intro') . '</p>' . ('' !== $rows
        ? '<table class="table table-striped"><thead><tr><th class="rex-table-icon"></th><th>' . I18n::e('calendar_single') . '</th><th>' . I18n::e('caldav_in_apps') . '</th></tr></thead><tbody>' . $rows . '</tbody></table>'
        : '<p class="scheduler-empty">' . I18n::e('caldav_no_calendars') . '</p>')
    . (Access::canManageCalendars($user) ? '<p class="help-block">' . I18n::t('caldav_calendars_admin_hint', Html::e(rex_url::backendPage('scheduler/calendars', [], false))) . '</p>' : ''),
    class: 'default',
);

// ---------- Verbinden ----------
if (!$dav->isAvailable()) {
    echo rex_view::warning(I18n::t('caldav_needs_dav') . ($user->isAdmin() ? ' <a href="' . Html::e(rex_url::backendPage('packages', [], false)) . '">' . I18n::e('caldav_to_addons') . '</a>' : ''));

    return;
}

echo new KLXM\Dav\Backend\ConnectPanel('caldav')->render();
