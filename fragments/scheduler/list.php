<?php

declare(strict_types=1);

/**
 * Terminliste. Eigene Darstellung: Datei in ein Projekt-Addon nach fragments/scheduler/list.php kopieren.
 *
 * Variablen: occurrences (list<Occurrence>), detail_article (int), group_by_month (bool),
 * show_teaser (bool), show_location (bool), show_calendar (bool), empty (string)
 *
 * @var rex_fragment $this
 */

use KLXM\Scheduler\Domain\Occurrence;
use KLXM\Scheduler\I18n;
use KLXM\Scheduler\Scheduler;
use KLXM\Scheduler\Frontend\Frontend;

/** @var list<Occurrence> $occurrences */
$occurrences = $this->getVar('occurrences', []);
$detailArticle = (int) $this->getVar('detail_article', 0);
$groupByMonth = (bool) $this->getVar('group_by_month', false);
$clangId = rex_clang::getCurrentId();
$locale = str_replace('_', '-', rex_clang::getCurrent()->getCode());

if ([] === $occurrences) {
    echo '<p class="scheduler-empty">' . rex_escape((string) $this->getVar('empty', I18n::front('front_empty'))) . '</p>';

    return;
}

$calendars = [];
foreach (Scheduler::calendars()->all() as $calendar) {
    $calendars[(int) $calendar->id] = $calendar;
}
$locations = Scheduler::locations()->findMany(array_values(array_filter(array_map(static fn (Occurrence $o): ?int => $o->event?->locationId, $occurrences))));
$monthFormat = new IntlDateFormatter($locale, IntlDateFormatter::NONE, IntlDateFormatter::NONE, null, null, 'LLLL yyyy');
$dayFormat = new IntlDateFormatter($locale, IntlDateFormatter::NONE, IntlDateFormatter::NONE, null, null, 'dd');
$shortMonthFormat = new IntlDateFormatter($locale, IntlDateFormatter::NONE, IntlDateFormatter::NONE, null, null, 'LLL');
$currentMonth = '';
?>
<div class="scheduler-list">
<?php foreach ($occurrences as $occurrence): ?>
    <?php
    $event = $occurrence->event;
    $translation = $event?->translation($clangId);
    $location = $locations[$event?->locationId ?? 0] ?? null;
    $calendar = $calendars[$occurrence->calendarId] ?? null;
    $cancelled = 'CANCELLED' === $event?->status->value;
    $month = $occurrence->start->format('Y-m');
    ?>
    <?php if ($groupByMonth && $month !== $currentMonth): $currentMonth = $month; ?>
        <h3 class="scheduler-list-month"><?= rex_escape((string) $monthFormat->format($occurrence->start)) ?></h3>
    <?php endif; ?>
    <article class="scheduler-item<?= $cancelled ? ' scheduler-is-cancelled' : '' ?>" style="--scheduler-color: <?= rex_escape($calendar?->color ?? '#3788d8') ?>">
        <time class="scheduler-item-date" datetime="<?= $occurrence->start->format($occurrence->allDay ? 'Y-m-d' : DATE_ATOM) ?>">
            <span class="scheduler-item-day"><?= rex_escape((string) $dayFormat->format($occurrence->start)) ?></span>
            <span class="scheduler-item-month"><?= rex_escape((string) $shortMonthFormat->format($occurrence->start)) ?></span>
        </time>
        <div class="scheduler-item-body">
            <h4 class="scheduler-item-title">
                <?php if ($detailArticle > 0): ?><a href="<?= Frontend::detailUrl($occurrence, $detailArticle) ?>"><?= rex_escape($occurrence->title($clangId)) ?></a>
                <?php else: ?><?= rex_escape($occurrence->title($clangId)) ?><?php endif; ?>
                <?php if ($cancelled): ?><span class="scheduler-badge"><?= rex_escape(I18n::front('front_cancelled')) ?></span><?php endif; ?>
            </h4>
            <p class="scheduler-item-meta">
                <?= rex_escape(Frontend::formatRange($occurrence, $locale)) ?>
                <?php if ($this->getVar('show_location', true) && (null !== $location || null !== $event?->locationText)): ?>
                    <span class="scheduler-item-location"><?= rex_escape($location?->name ?? (string) $event?->locationText) ?></span>
                <?php endif; ?>
                <?php if ($this->getVar('show_calendar', false) && null !== $calendar): ?>
                    <span class="scheduler-item-calendar"><?= rex_escape($calendar->name($clangId)) ?></span>
                <?php endif; ?>
            </p>
            <?php if ($this->getVar('show_teaser', true) && null !== $translation?->teaser): ?>
                <p class="scheduler-item-teaser"><?= nl2br(rex_escape($translation->teaser)) ?></p>
            <?php endif; ?>
        </div>
    </article>
<?php endforeach; ?>
</div>
