<?php

declare(strict_types=1);

namespace KLXM\Scheduler\Api;

use KLXM\Scheduler\I18n;
use DateTimeImmutable;
use KLXM\Scheduler\Color;
use KLXM\Scheduler\Domain\Calendar;
use KLXM\Scheduler\Domain\Enum\Visibility;
use KLXM\Scheduler\Domain\Occurrence;
use KLXM\Scheduler\Scheduler;
use KLXM\Scheduler\Frontend\Frontend;
use rex_api_function;
use rex_clang;
use rex_response;

/**
 * Öffentliche JSON-Ausgabe für Kalender im Frontend, im Format von FullCalendar.
 *
 *     index.php?rex-api-call=scheduler_events&start=2026-10-01&end=2026-11-01&calendars=1,3&detail=12
 *
 * Geliefert werden nur veröffentlichte, öffentliche Termine aktiver Kalender.
 */
final class EventsApi extends rex_api_function
{
    public const string NAME = 'scheduler_events';

    private const int MAX_DAYS = 400;

    protected $published = true;

    public function execute(): never
    {
        rex_response::cleanOutputBuffers();

        try {
            $start = new DateTimeImmutable(rex_request('start', 'string', 'first day of this month'));
            $end = new DateTimeImmutable(rex_request('end', 'string', 'first day of next month'));
        } catch (\Throwable) {
            rex_response::setStatus(rex_response::HTTP_BAD_REQUEST);
            rex_response::sendJson(['error' => I18n::t('error_period_invalid')]);
            exit;
        }
        // Begrenzung, damit niemand den ganzen Bestand auf einmal abfragt.
        if ($end > $start->modify('+' . self::MAX_DAYS . ' days')) {
            $end = $start->modify('+' . self::MAX_DAYS . ' days');
        }

        $clangId = rex_request('clang', 'int', rex_clang::getCurrentId());
        $clangId = rex_clang::exists($clangId) ? $clangId : rex_clang::getStartId();
        $detailArticle = rex_request('detail', 'int');

        $calendars = [];
        foreach (Scheduler::calendars()->all(true) as $calendar) {
            $calendars[(int) $calendar->id] = $calendar;
        }
        $wanted = array_filter(array_map(trim(...), explode(',', rex_request('calendars', 'string'))));
        if ([] !== $wanted) {
            $calendars = array_filter($calendars, static fn (Calendar $c): bool => in_array((string) $c->id, $wanted, true) || in_array($c->slug, $wanted, true));
        }

        $occurrences = [] === $calendars ? [] : Scheduler::occurrences()->between($start, $end)->inCalendars(...array_keys($calendars))->limit(2000)->get();
        $occurrences = array_filter($occurrences, static fn (Occurrence $o): bool => Visibility::Public === $o->event?->visibility);

        // yrewrite kennt /index.php nicht als Artikel und hat den Status vorab auf 404 gesetzt.
        rex_response::setStatus(rex_response::HTTP_OK);
        rex_response::setHeader('Cache-Control', 'public, max-age=120');
        rex_response::sendJson(array_values(array_map(static function (Occurrence $occurrence) use ($calendars, $clangId, $detailArticle): array {
            $calendar = $calendars[$occurrence->calendarId];

            return array_filter([
                'id' => $occurrence->eventId . ':' . $occurrence->recurrenceKey,
                'title' => $occurrence->title($clangId),
                'start' => $occurrence->start->format($occurrence->allDay ? 'Y-m-d' : 'Y-m-d\TH:i:s'),
                'end' => $occurrence->end->format($occurrence->allDay ? 'Y-m-d' : 'Y-m-d\TH:i:s'),
                'allDay' => $occurrence->allDay,
                'color' => $calendar->color,
                'textColor' => Color::textOn($calendar->color),
                'url' => $detailArticle > 0 ? Frontend::detailUrl($occurrence, $detailArticle, $clangId, false) : null,
                'classNames' => 'CANCELLED' === $occurrence->event?->status->value ? ['scheduler-is-cancelled'] : null,
                'extendedProps' => [
                    'calendar' => $calendar->name($clangId),
                    'teaser' => $occurrence->event?->translation($clangId)?->teaser,
                    'location' => $occurrence->event?->locationText,
                ],
            ], static fn (mixed $value): bool => null !== $value);
        }, $occurrences)));
        exit;
    }
}
