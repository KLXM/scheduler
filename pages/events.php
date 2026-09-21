<?php

declare(strict_types=1);

use KLXM\Scheduler\Api\BackendApi;
use KLXM\Scheduler\Backend\EventForm;
use KLXM\Scheduler\Backend\EventList;
use KLXM\Scheduler\Backend\Flash;
use KLXM\Scheduler\Backend\Html;
use KLXM\Scheduler\Domain\Event;
use KLXM\Scheduler\I18n;
use KLXM\Scheduler\Scheduler;
use KLXM\Scheduler\Recurrence\EditScope;
use KLXM\Scheduler\Recurrence\SeriesEditor;
use KLXM\Scheduler\Security\Access;
use KLXM\Scheduler\Service\ValidationException;

$func = rex_request('func', 'string');
$id = rex_request('id', 'int');
$returnTo = 'calendar' === rex_request('return', 'string') ? 'scheduler/calendar' : 'scheduler/events';
$csrf = rex_csrf_token::factory('scheduler_events');
$apiUrl = rex_url::backendPage('scheduler/events', BackendApi::getUrlParams(), false);

$clangs = [];
foreach ([rex_clang::getStartId(), ...rex_clang::getAllIds()] as $clangId) {
    $clangs[$clangId] ??= rex_clang::get($clangId)?->getName() ?? (string) $clangId;
}

echo Flash::render();

// ---------- Aktionen aus der Liste ----------
if (in_array($func, ['delete', 'toggle', 'duplicate'], true)) {
    $event = Scheduler::events()->find($id);
    if (!$csrf->isValid()) {
        Flash::error(rex_i18n::msg('csrf_token_invalid'));
    } elseif (null === $event || !Access::canEditEvent($event)) {
        Flash::error(I18n::t('event_not_editable'));
    } elseif ('delete' === $func && !Access::canDeleteEvent($event)) {
        Flash::error(I18n::t('event_delete_denied'));
    } elseif ('toggle' === $func && !Access::canPublish()) {
        Flash::error(I18n::t('event_publish_denied'));
    } elseif ('delete' === $func) {
        Scheduler::events()->delete($event);
        Flash::success(I18n::t('event_deleted'));
    } elseif ('toggle' === $func) {
        $event->published = !$event->published;
        Scheduler::events()->save($event);
        Flash::success(I18n::t($event->published ? 'event_now_online' : 'event_now_offline'));
    } else {
        $copy = new SeriesEditor()->duplicate($event);
        foreach ($copy->translations as $translation) {
            $translation->title .= ' ' . I18n::t('event_copy_suffix');
        }
        $copy->published = false;
        Scheduler::events()->save($copy);
        Flash::success(I18n::t('event_duplicated'));
        rex_response::sendRedirect(rex_url::backendPage('scheduler/events', ['func' => 'edit', 'id' => $copy->id], false));
    }
    rex_response::sendRedirect(rex_url::backendPage('scheduler/events', EventList::filterParams(), false));
}

// ---------- Anlegen und Bearbeiten ----------
if ('add' === $func || 'edit' === $func) {
    $event = 'edit' === $func ? Scheduler::events()->find($id) : null;
    if ('edit' === $func && null === $event) {
        echo rex_view::error(I18n::e('event_not_found'));

        return;
    }
    if (null !== $event && !Access::canEditEvent($event)) {
        echo rex_view::error(Access::canEditCalendar($event->calendarId)
            ? I18n::e('event_foreign_denied')
            : I18n::e('event_calendar_denied'));

        return;
    }

    if (null === $event) {
        $timezone = Scheduler::settings()->defaultTimezone();
        $zone = new DateTimeZone($timezone);
        $allDay = rex_get('all_day', 'bool', Scheduler::settings()->defaultAllDay());
        try {
            $start = new DateTimeImmutable(rex_get('start', 'string') ?: ($allDay ? 'today' : 'today 09:00'), $zone);
            $end = '' !== rex_get('end', 'string') ? new DateTimeImmutable(rex_get('end', 'string'), $zone) : null;
        } catch (Throwable) {
            $start = new DateTimeImmutable('today 09:00', $zone);
            $end = null;
        }
        $event = new Event($start, $end, $allDay, $timezone);
        $event->calendarId = rex_get('calendar_id', 'int') ?: (Access::editableCalendarIds()[0] ?? 0);
    }

    $form = new EventForm($event, $clangs);
    $occurrenceKey = rex_request('occurrence', 'string');

    if ('post' === rex_request_method()) {
        if (!$csrf->isValid()) {
            echo rex_view::error(rex_i18n::msg('csrf_token_invalid'));
        } else {
            $seriesAction = rex_post('series_action', 'string');
            try {
                if ('' !== $seriesAction && '' !== $occurrenceKey && $event->isRecurring) {
                    $scope = EditScope::tryFrom(rex_post('series_scope', 'string')) ?? EditScope::This;
                    if (new SeriesEditor()->remove($event, $occurrenceKey, $scope)) {
                        if (!Access::canDeleteEvent($event)) {
                            throw new ValidationException(['delete' => I18n::t('event_delete_denied')]);
                        }
                        Scheduler::events()->delete($event);
                    } else {
                        Scheduler::events()->save($event);
                    }
                    Flash::success(I18n::t(EditScope::This === $scope ? 'series_occurrence_cancelled' : 'series_occurrences_removed'));
                    rex_response::sendRedirect(rex_url::backendPage($returnTo, [], false));
                }

                $form->bind();
                if ([] === $form->errors) {
                    Scheduler::events()->save($event);
                    Flash::success(I18n::t('event_saved'));
                    $target = rex_post('save_and_close', 'bool')
                        ? rex_url::backendPage($returnTo, 'scheduler/events' === $returnTo ? EventList::filterParams() : [], false)
                        : rex_url::backendPage('scheduler/events', ['func' => 'edit', 'id' => $event->id, 'return' => rex_request('return', 'string')], false);
                    rex_response::sendRedirect($target);
                }
            } catch (ValidationException $e) {
                $form->errors += $e->errors;
            }
            if ([] !== $form->errors) {
                echo rex_view::error(I18n::e('check_input', implode(' ', array_unique($form->errors))));
            }
        }
    }

    $action = rex_url::backendPage('scheduler/events', array_filter(['func' => $func, 'id' => $event->id, 'return' => rex_request('return', 'string'), 'occurrence' => $occurrenceKey]), false);
    echo '<form action="' . Html::e($action) . '" method="post" class="scheduler-event-form">' . $csrf->getHiddenField();

    if ('' !== $occurrenceKey && $event->isRecurring) {
        $date = DateTimeImmutable::createFromFormat(8 === strlen($occurrenceKey) ? '!Ymd' : '!Ymd\THis', $occurrenceKey, new DateTimeZone($event->timezone));
        $when = false === $date ? $occurrenceKey : (string) new IntlDateFormatter(rex_i18n::getLocale(), IntlDateFormatter::FULL, $event->allDay ? IntlDateFormatter::NONE : IntlDateFormatter::SHORT, $event->timezone)->format($date);
        echo Html::section(
            I18n::e('series_title'),
            '<p>' . I18n::t('series_opened', Html::e($when)) . '</p>'
            . '<div class="scheduler-series-actions">'
            . '<button class="btn btn-default" type="submit" name="series_action" value="remove" formnovalidate onclick="this.form.series_scope.value=\'this\'"><i class="rex-icon fa-calendar-xmark"></i> ' . I18n::e('series_cancel_this') . '</button> '
            . '<button class="btn btn-default" type="submit" name="series_action" value="remove" formnovalidate onclick="this.form.series_scope.value=\'following\'" data-confirm="' . I18n::e('series_remove_following_confirm') . '"><i class="rex-icon fa-forward"></i> ' . I18n::e('series_remove_following') . '</button>'
            . '<input type="hidden" name="series_scope" value="this"></div>',
            class: 'info',
        );
    }

    echo $form->render($apiUrl);
    echo Html::formActions(
        rex_url::backendPage($returnTo, 'scheduler/events' === $returnTo ? EventList::filterParams() : [], false),
        null !== $event->id && Access::canDeleteEvent($event)
            ? ' <a class="btn btn-delete scheduler-push" data-confirm="' . I18n::e('event_delete_confirm') . '" href="' . Html::e(rex_url::backendPage('scheduler/events', ['func' => 'delete', 'id' => $event->id] + $csrf->getUrlParams(), false)) . '">' . I18n::e('delete') . '</a>'
            : '',
    ) . '</form>';

    return;
}

// ---------- Liste ----------
echo new EventList($csrf)->render();
