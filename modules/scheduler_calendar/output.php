<?php

use KLXM\Scheduler\Frontend\Frontend;

if (rex::isBackend()) {
    echo '<p><i class="rex-icon fa-calendar"></i> ' . \KLXM\Scheduler\I18n::e('module_calendar_preview') . '</p>';

    return;
}

$fragment = new rex_fragment();
$fragment->setVar('calendars', array_values(array_filter(array_map('intval', (array) rex_var::toArray('REX_VALUE[1]')))), false);
$fragment->setVar('view', 'REX_VALUE[2]');
$fragment->setVar('detail_article', (int) 'REX_LINK[1]');

echo Frontend::stylesheet();
echo $fragment->parse('scheduler/calendar.php');
