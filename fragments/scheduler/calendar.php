<?php

declare(strict_types=1);

/**
 * Interaktiver Kalender im Frontend (FullCalendar). Lädt nur den sichtbaren Zeitraum nach.
 *
 * Variablen: calendars (list<int|string>), detail_article (int), view (string), height (string)
 *
 * @var rex_fragment $this
 */

use KLXM\Scheduler\I18n;
use KLXM\Scheduler\Scheduler;

$addon = rex_addon::get('scheduler');
$feed = rex_url::frontendController(array_filter([
    'rex-api-call' => 'scheduler_events',
    'calendars' => implode(',', (array) $this->getVar('calendars', [])),
    'detail' => (int) $this->getVar('detail_article', 0),
    'clang' => rex_clang::getCurrentId(),
]), false);
$views = ['dayGridMonth', 'timeGridWeek', 'listMonth', 'multiMonthYear'];
$view = in_array($this->getVar('view'), $views, true) ? (string) $this->getVar('view') : 'dayGridMonth';
?>
<scheduler-frontend-calendar
    feed="<?= rex_escape($feed) ?>"
    view="<?= rex_escape($view) ?>"
    locale="<?= rex_escape(substr(rex_clang::getCurrent()->getCode(), 0, 2)) ?>"
    first-day="<?= Scheduler::settings()->weekStartsOn() ?>"
    script="<?= rex_escape($addon->getAssetsUrl('vendor/fullcalendar/index.global.min.js')) ?>"
    locales="<?= rex_escape($addon->getAssetsUrl('vendor/fullcalendar/locales-all.global.min.js')) ?>">
    <noscript><?= rex_escape(I18n::front('front_noscript')) ?></noscript>
</scheduler-frontend-calendar>
<script type="module" src="<?= rex_escape($addon->getAssetsUrl('scheduler-frontend.js')) ?>"></script>
