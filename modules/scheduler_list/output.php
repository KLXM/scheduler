<?php

use KLXM\Scheduler\Scheduler;
use KLXM\Scheduler\Frontend\Frontend;

$calendarIds = array_values(array_filter(array_map('intval', (array) rex_var::toArray('REX_VALUE[1]'))));
$months = (int) 'REX_VALUE[3]';

$query = Scheduler::occurrences()->upcoming()->limit(max(1, (int) 'REX_VALUE[2]' ?: 10));
if ([] !== $calendarIds) {
    $query->inCalendars(...$calendarIds);
}
if ($months > 0) {
    $query->until(new DateTimeImmutable('+' . $months . ' months'));
}
if ('' !== trim('REX_VALUE[7]')) {
    $query->withCategory(trim('REX_VALUE[7]'));
}

$fragment = new rex_fragment();
$fragment->setVar('occurrences', $query->get(), false);
$fragment->setVar('detail_article', (int) 'REX_LINK[1]');
$fragment->setVar('group_by_month', '1' === 'REX_VALUE[5]');
$fragment->setVar('show_teaser', '0' !== 'REX_VALUE[6]');

if ('0' !== 'REX_VALUE[8]') {
    echo Frontend::stylesheet();
}
if ('' !== trim('REX_VALUE[4]')) {
    echo '<h2>' . rex_escape('REX_VALUE[4]') . '</h2>';
}
echo $fragment->parse('scheduler/list.php');
