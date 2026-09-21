<?php

declare(strict_types=1);

/**
 * Detailansicht eines Termins.
 *
 * Variablen: occurrence (Occurrence), back_url (string), upcoming (int, Zahl weiterer Serientermine),
 * show_map (bool, Karte des Ortes, wenn das Addon vector_maps aktiv ist)
 *
 * @var rex_fragment $this
 */

use KLXM\Scheduler\Domain\Occurrence;
use KLXM\Scheduler\I18n;
use KLXM\Scheduler\Scheduler;
use KLXM\Scheduler\Frontend\Frontend;
use KLXM\Scheduler\Recurrence\Rule;

/** @var Occurrence $occurrence */
$occurrence = $this->getVar('occurrence');
$event = $occurrence->event;
if (null === $event) {
    return;
}

$clangId = rex_clang::getCurrentId();
$locale = str_replace('_', '-', rex_clang::getCurrent()->getCode());
$translation = $event->translation($clangId);
$location = null !== $event->locationId ? Scheduler::locations()->find($event->locationId) : null;
$cancelled = 'CANCELLED' === $event->status->value;
$backUrl = (string) $this->getVar('back_url', '');

$further = [];
if ($event->isRecurring && (int) $this->getVar('upcoming', 5) > 0) {
    $further = array_filter(
        Scheduler::occurrences()->forEvents((int) $event->id)->from($occurrence->end)->limit((int) $this->getVar('upcoming', 5))->get(),
        static fn (Occurrence $other): bool => $other->recurrenceKey !== $occurrence->recurrenceKey,
    );
}
?>
<article class="scheduler-detail<?= $cancelled ? ' scheduler-is-cancelled' : '' ?>">
    <header>
        <h1 class="scheduler-detail-title"><?= rex_escape($occurrence->title($clangId)) ?><?php if ($cancelled): ?> <span class="scheduler-badge"><?= rex_escape(I18n::front('front_cancelled')) ?></span><?php endif; ?></h1>
        <p class="scheduler-detail-when"><time datetime="<?= $occurrence->start->format($occurrence->allDay ? 'Y-m-d' : DATE_ATOM) ?>"><?= rex_escape(Frontend::formatRange($occurrence, $locale)) ?></time></p>
        <?php if (null !== $event->rrule): ?>
            <p class="scheduler-detail-rule"><?= rex_escape(ucfirst(Rule::parse($event->rrule)->toText(substr($locale, 0, 2), $event->dtstart))) ?></p>
        <?php endif; ?>
    </header>

    <?php if (null !== $location || null !== $event->locationText): ?>
        <address class="scheduler-detail-location">
            <?php if (null !== $location): ?>
                <strong><?= rex_escape($location->name) ?></strong><br>
                <?= rex_escape(implode(', ', array_filter([$location->street, trim($location->zip . ' ' . $location->city)]))) ?>
                <?php if (null !== $location->url): ?><br><a href="<?= rex_escape($location->url) ?>" rel="noopener"><?= rex_escape((string) parse_url($location->url, PHP_URL_HOST)) ?></a><?php endif; ?>
            <?php else: ?>
                <?= rex_escape((string) $event->locationText) ?>
            <?php endif; ?>
        </address>
    <?php endif; ?>

    <?php if ($this->getVar('show_map', true) && true === $location?->hasGeo && rex_addon::get('vector_maps')->isAvailable()): ?>
        <?php /* Karte über das Addon vector_maps; dessen Assets lädt es im Frontend selbst. */ ?>
        <vectormap class="scheduler-detail-map" lat="<?= (float) $location->latitude ?>" lng="<?= (float) $location->longitude ?>" zoom="15" height="320px"
            markers="<?= rex_escape(json_encode([['lat' => $location->latitude, 'lng' => $location->longitude, 'popup' => $location->name]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)) ?>"></vectormap>
    <?php endif; ?>

    <?php if (null !== $translation?->teaser): ?><p class="scheduler-detail-teaser"><?= nl2br(rex_escape($translation->teaser)) ?></p><?php endif; ?>
    <?php /* Die Beschreibung stammt aus dem WYSIWYG-Editor des Backends und ist gewolltes HTML. */ ?>
    <?php if (null !== $translation?->description): ?><div class="scheduler-detail-text"><?= $translation->description ?></div><?php endif; ?>

    <?php if ([] !== $further): ?>
        <section class="scheduler-detail-further">
            <h2><?= rex_escape(I18n::front('front_more_dates')) ?></h2>
            <ul>
                <?php foreach ($further as $other): ?><li><?= rex_escape(Frontend::formatRange($other, $locale)) ?></li><?php endforeach; ?>
            </ul>
        </section>
    <?php endif; ?>

    <p class="scheduler-detail-actions">
        <a class="scheduler-button" href="<?= rex_escape(Frontend::icsUrl($event)) ?>"><?= rex_escape(I18n::front('front_add_to_calendar')) ?></a>
        <?php if (null !== $event->url): ?><a class="scheduler-button" href="<?= rex_escape($event->url) ?>" rel="noopener"><?= rex_escape(I18n::front('front_more_info')) ?></a><?php endif; ?>
        <?php if ('' !== $backUrl): ?><a class="scheduler-button scheduler-button-plain" href="<?= rex_escape($backUrl) ?>"><?= rex_escape(I18n::front('front_back')) ?></a><?php endif; ?>
    </p>

    <script type="application/ld+json"><?= json_encode(Frontend::jsonLd($occurrence, $location), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?></script>
</article>
