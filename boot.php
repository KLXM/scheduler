<?php

declare(strict_types=1);

use KLXM\Scheduler\Api\BackendApi;
use KLXM\Scheduler\Api\FeedApi;
use KLXM\Scheduler\Security\CalendarPerm;

// sabre/vobject und sabre/dav kommen aus dem Addon dav, das vor scheduler startet. Hier nur die eigene RRULE-Bibliothek.
require_once __DIR__ . '/vendor/autoload.php';

$addon = rex_addon::get('scheduler');

rex_api_function::register(BackendApi::NAME, BackendApi::class);
rex_api_function::register(FeedApi::NAME, FeedApi::class);
rex_api_function::register(KLXM\Scheduler\Api\EventsApi::NAME, KLXM\Scheduler\Api\EventsApi::class);

// Das builder-Addon findet das Element "scheduler_list" über diesen Pfad.
if (rex_addon::get('builder')->isAvailable()) {
    rex_extension::register('BUILDER_ELEMENT_PATHS', static function (rex_extension_point $ep): array {
        return [...(array) $ep->getSubject(), rex_path::addon('scheduler', 'elements/')];
    }, rex_extension::EARLY);
}

if (rex_addon::get('cronjob')->isAvailable()) {
    rex_cronjob_manager::registerType(KLXM\Scheduler\Cronjob\HorizonCronjob::class);
    rex_cronjob_manager::registerType(KLXM\Scheduler\Cronjob\SubscriptionCronjob::class);
}

// CalDAV: Server, Anmeldung, App-Passwörter und die sabre-Bibliotheken stellt das Addon dav. scheduler hängt nur seine Kalender ein.
KLXM\Dav\Dav::register(new KLXM\Scheduler\Dav\CalendarProvider());

if (rex::isBackend()) {
    rex_complex_perm::register(CalendarPerm::KEY, CalendarPerm::class);
    rex_perm::register('scheduler[calendars]', null, rex_perm::OPTIONS);
    rex_perm::register('scheduler[locations]', null, rex_perm::OPTIONS);
    rex_perm::register(KLXM\Scheduler\Security\Access::PERM_EDIT_FOREIGN, null, rex_perm::OPTIONS);
    rex_perm::register(KLXM\Scheduler\Security\Access::PERM_PUBLISH, null, rex_perm::OPTIONS);
    rex_perm::register(KLXM\Scheduler\Security\Access::PERM_DELETE, null, rex_perm::OPTIONS);

    if (null !== rex::getUser() && 'scheduler' === rex_be_controller::getCurrentPagePart(1)) {
        $version = $addon->getVersion();
        rex_view::addCssFile($addon->getAssetsUrl('scheduler.css?v=' . $version));
        rex_view::setJsProperty('scheduler_i18n', KLXM\Scheduler\I18n::forJavaScript());
        if ('calendar' === rex_be_controller::getCurrentPagePart(2)) {
            rex_view::addJsFile($addon->getAssetsUrl('vendor/fullcalendar/index.global.min.js'), [rex_view::JS_IMMUTABLE => true]);
            rex_view::addJsFile($addon->getAssetsUrl('vendor/fullcalendar/locales-all.global.min.js'), [rex_view::JS_IMMUTABLE => true]);
        }

        // Die Komponenten sind ES-Module; rex_view kennt dafür keinen Schalter.
        rex_extension::register('PAGE_HEADER', static function (rex_extension_point $ep) use ($addon, $version): string {
            return $ep->getSubject() . '<script type="module" src="' . rex_escape($addon->getAssetsUrl('scheduler.js?v=' . $version)) . '"></script>';
        });
    }
}
