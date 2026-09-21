<?php

declare(strict_types=1);

namespace KLXM\Scheduler\Api;

use KLXM\Scheduler\I18n;
use DateTimeImmutable;
use DateTimeZone;
use KLXM\Scheduler\Domain\Calendar;
use KLXM\Scheduler\Domain\Enum\Visibility;
use KLXM\Scheduler\Scheduler;
use KLXM\Scheduler\Ical\FeedBuilder;
use KLXM\Scheduler\Security\Access;
use rex_api_function;
use rex_clang;
use rex_response;

/**
 * Öffentliche ICS-Ausgabe.
 *
 *     index.php?rex-api-call=scheduler_feed&calendar=schule          ein Kalender per Kurzname oder ID
 *     index.php?rex-api-call=scheduler_feed&calendar=schule,verein   mehrere Kalender
 *     index.php?rex-api-call=scheduler_feed&event=42                 ein einzelner Termin zum Herunterladen
 *
 * Ohne Anmeldung sind nur Kalender mit aktiviertem öffentlichem Feed erreichbar.
 */
final class FeedApi extends rex_api_function
{
    public const string NAME = 'scheduler_feed';

    protected $published = true;

    public function execute(): never
    {
        rex_response::cleanOutputBuffers();
        $clangId = rex_request('clang', 'int', rex_clang::getCurrentId());
        if (!rex_clang::exists($clangId)) {
            $clangId = rex_clang::getStartId();
        }
        $isBackendUser = \rex::isBackend() && Access::canUse();
        $builder = new FeedBuilder();

        $eventId = rex_request('event', 'int');
        if ($eventId > 0) {
            $event = Scheduler::events()->find($eventId);
            $calendar = null !== $event ? Scheduler::calendars()->find($event->calendarId) : null;
            $visible = null !== $event && null !== $calendar
                && ($isBackendUser || ($event->published && $calendar->active && Visibility::Public === $event->visibility));
            if (!$visible) {
                $this->notFound();
            }
            $this->send($builder->forEvent($event, $clangId), $event->etag, 'termin-' . $eventId, download: true);
        }

        $calendars = [];
        foreach (array_filter(array_map(trim(...), explode(',', rex_request('calendar', 'string')))) as $key) {
            $calendar = ctype_digit($key) ? Scheduler::calendars()->find((int) $key) : Scheduler::calendars()->findBySlug($key);
            if (null !== $calendar && ($isBackendUser || ($calendar->active && $calendar->publicFeed))) {
                $calendars[(int) $calendar->id] = $calendar;
            }
        }
        if ([] === $calendars) {
            $this->notFound();
        }

        $settings = Scheduler::settings();
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        // Der ETag hängt an den Synchronisationszählern: ändert sich nichts, antwortet der Server mit 304.
        $etag = sha1(implode('|', array_map(static fn (Calendar $c): string => $c->id . ':' . $c->syncToken, $calendars)) . '|' . $clangId . '|' . $now->format('Y-m-d'));

        $this->send(
            $builder->forCalendars(array_values($calendars), $clangId, $now->modify('-' . $settings->feedMonthsBack() . ' months'), $now->modify('+' . $settings->feedMonthsAhead() . ' months'), !$isBackendUser),
            $etag,
            implode('-', array_map(static fn (Calendar $c): string => $c->slug, $calendars)),
        );
    }

    private function send(string $ics, string $etag, string $filename, bool $download = false): never
    {
        $etag = '"' . $etag . '"';
        // yrewrite kennt /index.php nicht als Artikel und hat den Status vorab auf 404 gesetzt.
        rex_response::setStatus(rex_response::HTTP_OK);
        rex_response::setHeader('ETag', $etag);
        rex_response::setHeader('Cache-Control', 'public, max-age=300');
        if (trim((string) rex_server('HTTP_IF_NONE_MATCH', 'string')) === $etag) {
            rex_response::setStatus(rex_response::HTTP_NOT_MODIFIED);
            rex_response::sendContent('');
            exit;
        }

        rex_response::setHeader('Content-Disposition', ($download ? 'attachment' : 'inline') . '; filename="' . preg_replace('/[^a-z0-9\-]+/i', '-', $filename) . '.ics"');
        rex_response::sendContent($ics, 'text/calendar; charset=utf-8');
        exit;
    }

    private function notFound(): never
    {
        rex_response::setStatus(rex_response::HTTP_NOT_FOUND);
        rex_response::sendContent(I18n::t('feed_not_found'), 'text/plain; charset=utf-8');
        exit;
    }
}
