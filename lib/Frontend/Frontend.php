<?php

declare(strict_types=1);

namespace KLXM\Scheduler\Frontend;

use DateTimeImmutable;
use DateTimeZone;
use KLXM\Scheduler\Domain\Calendar;
use KLXM\Scheduler\Domain\Event;
use KLXM\Scheduler\Domain\Location;
use KLXM\Scheduler\Domain\Occurrence;
use KLXM\Scheduler\Scheduler;
use IntlDateFormatter;
use rex_clang;

/**
 * Helfer für Module, Templates und Fragmente im Frontend.
 */
final class Frontend
{
    /**
     * Link zur Detailansicht eines Vorkommens. Der Artikel enthält das Modul "scheduler: Termin".
     */
    public static function detailUrl(Occurrence $occurrence, int $articleId, ?int $clangId = null, bool $forHtml = true): string
    {
        $params = ['event' => $occurrence->eventId];
        if (true === $occurrence->event?->isRecurring) {
            $params['date'] = $occurrence->start->format('Y-m-d');
        }

        // rex_getUrl() maskiert das & für HTML; in JSON oder Redirects muss es roh bleiben.
        return rex_getUrl($articleId, $clangId ?? rex_clang::getCurrentId(), $params, $forHtml ? '&amp;' : '&');
    }

    public static function icsUrl(Event $event): string
    {
        return \rex_url::frontendController(['rex-api-call' => 'scheduler_feed', 'event' => (int) $event->id]);
    }

    /**
     * Abo-Adresse eines Kalenders. Mit webcal:// öffnen Kalender-Apps direkt den Abo-Dialog.
     */
    public static function feedUrl(Calendar $calendar, bool $webcal = false): string
    {
        $url = rtrim(\rex::getServer(), '/') . '/index.php?rex-api-call=scheduler_feed&calendar=' . rawurlencode($calendar->slug);

        return $webcal ? (string) preg_replace('#^https?://#', 'webcal://', $url) : $url;
    }

    /**
     * Lesbarer Zeitraum: "Dienstag, 6. Oktober 2026, 16:00 bis 17:30 Uhr" oder "12. bis 14. Oktober 2026".
     */
    public static function formatRange(Occurrence $occurrence, ?string $locale = null): string
    {
        $locale ??= str_replace('_', '-', rex_clang::getCurrent()->getCode());
        $zone = $occurrence->timezone;
        $date = new IntlDateFormatter($locale, IntlDateFormatter::FULL, IntlDateFormatter::NONE, $zone);
        $time = new IntlDateFormatter($locale, IntlDateFormatter::NONE, IntlDateFormatter::SHORT, $zone);
        $start = $occurrence->start;
        $end = $occurrence->allDay ? $occurrence->lastDay : $occurrence->end;
        $sameDay = $start->format('Ymd') === $end->format('Ymd');

        if ($occurrence->allDay) {
            return $sameDay ? (string) $date->format($start) : $date->format($start) . ' – ' . $date->format($end);
        }
        if ($sameDay) {
            return $date->format($start) . ', ' . $time->format($start) . ($end > $start ? ' – ' . $time->format($end) : '');
        }

        return $date->format($start) . ', ' . $time->format($start) . ' – ' . $date->format($end) . ', ' . $time->format($end);
    }

    /**
     * Das Vorkommen zu einer Detail-URL: ?event=ID, bei Serien zusätzlich &date=JJJJ-MM-TT.
     * Ohne Datum das nächste, sonst das letzte Vorkommen.
     */
    public static function occurrenceFromRequest(): ?Occurrence
    {
        $eventId = rex_request('event', 'int');
        if ($eventId <= 0) {
            return null;
        }

        $date = rex_request('date', 'string');
        if (1 === preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $zone = new DateTimeZone(Scheduler::settings()->defaultTimezone());
            $day = new DateTimeImmutable($date, $zone);
            foreach (Scheduler::occurrences()->forEvents($eventId)->between($day->modify('-1 day'), $day->modify('+2 days'))->get() as $occurrence) {
                if ($occurrence->start->format('Y-m-d') === $date) {
                    return $occurrence;
                }
            }
        }

        return Scheduler::occurrences()->forEvents($eventId)->upcoming()->first()
            ?? Scheduler::occurrences()->forEvents($eventId)->latestFirst()->first();
    }

    /**
     * schema.org-Daten für Suchmaschinen.
     *
     * @return array<string, mixed>
     */
    public static function jsonLd(Occurrence $occurrence, ?Location $location = null, ?string $url = null): array
    {
        $event = $occurrence->event;
        $clangId = rex_clang::getCurrentId();
        $format = $occurrence->allDay ? 'Y-m-d' : DATE_ATOM;

        $data = [
            '@context' => 'https://schema.org',
            '@type' => 'Event',
            'name' => $occurrence->title($clangId),
            'startDate' => $occurrence->start->format($format),
            'endDate' => ($occurrence->allDay ? $occurrence->lastDay : $occurrence->end)->format($format),
            'eventStatus' => 'https://schema.org/' . ('CANCELLED' === $event?->status->value ? 'EventCancelled' : 'EventScheduled'),
        ];
        $description = $event?->translation($clangId)?->plainDescription;
        if (null !== $description && '' !== $description) {
            $data['description'] = $description;
        }
        if (null !== $url) {
            $data['url'] = $url;
        }
        if (null !== $location) {
            $data['location'] = array_filter([
                '@type' => 'Place',
                'name' => $location->name,
                'address' => array_filter([
                    '@type' => 'PostalAddress',
                    'streetAddress' => $location->street,
                    'postalCode' => $location->zip,
                    'addressLocality' => $location->city,
                    'addressCountry' => $location->country,
                ]),
                'geo' => $location->hasGeo ? ['@type' => 'GeoCoordinates', 'latitude' => $location->latitude, 'longitude' => $location->longitude] : null,
            ]);
        } elseif (null !== $event?->locationText) {
            $data['location'] = ['@type' => 'Place', 'name' => $event->locationText];
        }
        if (null !== $event?->organizer) {
            $data['organizer'] = ['@type' => 'Organization', 'name' => $event->organizer];
        }

        return $data;
    }

    /**
     * Bindet das mitgelieferte Stylesheet genau einmal je Seite ein.
     */
    public static function stylesheet(): string
    {
        static $sent = false;
        if ($sent) {
            return '';
        }
        $sent = true;

        return '<link rel="stylesheet" href="' . rex_escape(\rex_addon::get('scheduler')->getAssetsUrl('scheduler-frontend.css')) . '">';
    }
}
