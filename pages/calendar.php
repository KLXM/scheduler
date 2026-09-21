<?php

declare(strict_types=1);

use KLXM\Scheduler\Api\BackendApi;
use KLXM\Scheduler\Backend\Flash;
use KLXM\Scheduler\Backend\Html;
use KLXM\Scheduler\I18n;
use KLXM\Scheduler\Scheduler;
use KLXM\Scheduler\Security\Access;

echo Flash::render();

$calendars = [];
foreach (Scheduler::calendars()->all() as $calendar) {
    $calendars[] = [
        'id' => (int) $calendar->id,
        'name' => $calendar->name,
        'color' => $calendar->color,
        'editable' => Access::canEditCalendar((int) $calendar->id),
    ];
}

if ([] === $calendars) {
    echo rex_view::info(I18n::t('calendar_view_empty', Html::e(rex_url::backendPage('scheduler/calendars', ['func' => 'add'], false))));

    return;
}

printf(
    '<scheduler-calendar api="%s" edit-url="%s" calendars="%s" locale="%s" first-day="%d"></scheduler-calendar>',
    Html::e(rex_url::backendPage('scheduler/calendar', BackendApi::getUrlParams(), false)),
    Html::e(rex_url::backendPage('scheduler/events', [], false)),
    Html::e(json_encode($calendars, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)),
    Html::e(substr(rex_i18n::getLocale(), 0, 2)),
    Scheduler::settings()->weekStartsOn(),
);
