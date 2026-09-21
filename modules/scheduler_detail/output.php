<?php

use KLXM\Scheduler\Domain\Enum\Visibility;
use KLXM\Scheduler\Frontend\Frontend;

if (rex::isBackend()) {
    echo '<p><i class="rex-icon fa-calendar-day"></i> ' . \KLXM\Scheduler\I18n::e('module_detail_preview') . '</p>';

    return;
}

$overviewId = (int) 'REX_LINK[1]';
$occurrence = Frontend::occurrenceFromRequest();

// Offline-Termine liefert die Abfrage gar nicht erst; private bleiben hier außen vor.
if (null === $occurrence || Visibility::Public !== $occurrence->event?->visibility) {
    if ($overviewId > 0 && 0 === rex_request('event', 'int')) {
        rex_response::sendRedirect(rex_getUrl($overviewId));
    }
    rex_response::setStatus(rex_response::HTTP_NOT_FOUND);
    echo '<p class="scheduler-empty">' . rex_escape(\KLXM\Scheduler\I18n::front('front_event_gone')) . '</p>';

    return;
}

$fragment = new rex_fragment();
$fragment->setVar('occurrence', $occurrence, false);
$fragment->setVar('back_url', $overviewId > 0 ? rex_getUrl($overviewId) : '');
$fragment->setVar('upcoming', (int) ('REX_VALUE[1]' !== '' ? 'REX_VALUE[1]' : 5));

echo Frontend::stylesheet();
echo $fragment->parse('scheduler/detail.php');
