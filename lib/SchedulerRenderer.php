<?php

declare(strict_types=1);

namespace KLXM\Scheduler;

use DateTimeImmutable;
use DateTimeInterface;
use KLXM\Scheduler\Domain\Calendar;
use KLXM\Scheduler\Domain\Location;
use KLXM\Scheduler\Domain\Occurrence;
use IntlDateFormatter;
use rex_clang;
use rex_logger;
use rex_media_manager;
use Throwable;

/**
 * Datenlieferant für das Builder-Element "scheduler_list" (elements/scheduler_list).
 * Die Templates des Elements erwarten flache Item-Arrays; die Daten kommen aus Scheduler::occurrences().
 */
final class SchedulerRenderer
{
    /** @var list<string> */
    public const array ALLOWED_LAYOUTS = ['cards', 'list', 'compact'];

    public const int MAX_LIMIT = 50;

    /**
     * @param array<string, mixed> $elementData
     * @return array{layout: string, items: list<array<string, mixed>>, error: ?string, limit: int}
     */
    public static function fetch(array $elementData): array
    {
        $layout = in_array($elementData['layout'] ?? '', self::ALLOWED_LAYOUTS, true) ? (string) $elementData['layout'] : 'cards';
        $limit = (int) ($elementData['limit'] ?? 6);
        $limit = $limit < 1 ? 6 : min($limit, self::MAX_LIMIT);
        $teaserLength = min(800, max(30, (int) ($elementData['teaser_length'] ?? 160)));

        try {
            [$from, $to] = self::dateRange($elementData);
            $query = Scheduler::occurrences()->between($from, $to)->limit($limit);

            if ('repeat' === ($elementData['mode'] ?? 'categories')) {
                $eventId = (int) ($elementData['repeat_entry'] ?? 0);
                if ($eventId <= 0) {
                    return ['layout' => $layout, 'items' => [], 'error' => null, 'limit' => $limit];
                }
                $query->forEvents($eventId);
            } else {
                $calendarIds = self::ids($elementData['categories'] ?? '');
                if ([] !== $calendarIds) {
                    $query->inCalendars(...$calendarIds);
                }
                $locationId = !empty($elementData['filter_by_venue']) ? (int) ($elementData['venue_id'] ?? 0) : 0;
                if ($locationId > 0) {
                    $query->atLocations($locationId);
                }
            }

            $occurrences = $query->get();
        } catch (Throwable $e) {
            rex_logger::logException($e);

            return ['layout' => $layout, 'items' => [], 'error' => 'Fehler beim Laden der Termine.', 'limit' => 0];
        }

        $calendars = [];
        foreach (Scheduler::calendars()->all() as $calendar) {
            $calendars[(int) $calendar->id] = $calendar;
        }
        $locations = Scheduler::locations()->findMany(array_values(array_filter(array_map(
            static fn (Occurrence $occurrence): ?int => $occurrence->event?->locationId,
            $occurrences,
        ))));

        $items = [];
        foreach ($occurrences as $occurrence) {
            $items[] = self::item($occurrence, $calendars, $locations, $teaserLength, $elementData);
        }

        return ['layout' => $layout, 'items' => $items, 'error' => null, 'limit' => $limit];
    }

    /**
     * @param array<string, mixed> $item
     */
    public static function formatDate(array $item): string
    {
        $start = $item['start'] ?? null;
        if (!$start instanceof DateTimeInterface) {
            return '';
        }
        $date = $start->format('d.m.Y');
        if (!empty($item['full_time'])) {
            $end = $item['last_day'] ?? null;

            return ($end instanceof DateTimeInterface && $end->format('Ymd') !== $start->format('Ymd')) ? $date . ' &ndash; ' . $end->format('d.m.Y') : $date;
        }

        return $date . ' &middot; ' . $start->format('H:i') . ' Uhr';
    }

    /**
     * Baut eine lineare Liste aus Trennzeilen (Jahr, Monat) und Items.
     *
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    public static function buildSeparatedRows(array $items, string $groupBy): array
    {
        if (!in_array($groupBy, ['year', 'month', 'year_month'], true)) {
            return array_map(static fn (array $item): array => ['type' => 'item', 'item' => $item], $items);
        }

        $rows = [];
        $lastYear = $lastMonth = '';
        foreach ($items as $item) {
            $start = $item['start'] ?? null;
            if ($start instanceof DateTimeInterface) {
                $year = $start->format('Y');
                $month = $start->format('Y-m');
                if ('month' !== $groupBy && $year !== $lastYear) {
                    $rows[] = ['type' => 'separator', 'level' => 1, 'label' => $year, 'key' => $year];
                    $lastMonth = '';
                }
                if ('year' !== $groupBy && $month !== $lastMonth) {
                    $rows[] = ['type' => 'separator', 'level' => 'month' === $groupBy ? 1 : 2, 'label' => self::monthLabel($start), 'key' => $month];
                }
                [$lastYear, $lastMonth] = [$year, $month];
            }
            $rows[] = ['type' => 'item', 'item' => $item];
        }

        return $rows;
    }

    /**
     * @return array<int, string>
     */
    public static function getCategoryChoices(): array
    {
        $choices = [];
        foreach (self::safe(static fn (): array => Scheduler::calendars()->all(true)) as $calendar) {
            $choices[(int) $calendar->id] = $calendar->name(rex_clang::getCurrentId());
        }

        return $choices;
    }

    /**
     * @return array<int, string>
     */
    public static function getRepeatingEntryChoices(): array
    {
        $choices = [];
        $events = self::safe(static fn (): array => Scheduler::events()->query()->whereNotNull('rrule')->where('published', true)->orderBy('dtstart', 'desc')->limit(200)->get());
        foreach ($events as $event) {
            $choices[(int) $event->id] = $event->title(rex_clang::getCurrentId());
        }

        return $choices;
    }

    /**
     * @return array<int, string>
     */
    public static function getVenueChoices(): array
    {
        $choices = [];
        foreach (self::safe(static fn (): array => Scheduler::locations()->query()->where('active', true)->orderBy('name')->get()) as $location) {
            $choices[(int) $location->id] = $location->name;
        }

        return $choices;
    }

    public static function isAvailable(): bool
    {
        return \rex_addon::get('scheduler')->isAvailable();
    }

    /**
     * @param array<int, Calendar> $calendars
     * @param array<int, Location> $locations
     * @param array<string, mixed> $elementData
     * @return array<string, mixed>
     */
    private static function item(Occurrence $occurrence, array $calendars, array $locations, int $teaserLength, array $elementData): array
    {
        $event = $occurrence->event;
        $clangId = rex_clang::getCurrentId();
        $translation = $event?->translation($clangId);
        $calendar = $calendars[$occurrence->calendarId] ?? null;
        $location = $locations[$event->locationId ?? 0] ?? null;

        $teaser = trim((string) preg_replace('/\s+/', ' ', strip_tags((string) ($translation->teaser ?? $translation->description ?? ''))));
        if (mb_strlen($teaser) > $teaserLength) {
            $teaser = rtrim(mb_substr($teaser, 0, $teaserLength - 1)) . '…';
        }

        $image = '';
        if (!empty($elementData['show_image']) && null !== $event) {
            $configured = trim((string) ($elementData['image_field'] ?? ''));
            foreach ('' !== $configured ? [$configured] : ['image', 'lang_image', 'bild', 'header_image', 'teaser_image'] as $field) {
                $value = $translation?->custom[$field] ?? $event->custom[$field] ?? null;
                $value = is_array($value) ? reset($value) : $value;
                if (is_string($value) && '' !== trim($value)) {
                    $image = trim(explode(',', $value)[0]);
                    break;
                }
            }
        }

        $urlPattern = (string) ($elementData['url_pattern'] ?? '');

        return [
            'id' => $occurrence->eventId,
            'title' => $occurrence->title($clangId),
            'teaser' => $teaser,
            'category_name' => $calendar?->name($clangId) ?? '',
            'category_color' => $calendar->color ?? '',
            'start' => $occurrence->start,
            'end' => $occurrence->end,
            'last_day' => $occurrence->lastDay,
            'start_time' => $occurrence->allDay ? '' : $occurrence->start->format('H:i:s'),
            'end_time' => $occurrence->allDay ? '' : $occurrence->end->format('H:i:s'),
            'full_time' => $occurrence->allDay,
            'venue' => $location->name ?? (string) $event?->locationText,
            'href' => '' !== $urlPattern ? str_replace(['{id}', '{date}'], [(string) $occurrence->eventId, $occurrence->start->format('Y-m-d')], $urlPattern) : '',
            'image' => $image,
            'image_url' => '' !== $image ? rex_media_manager::getUrl('card', $image) : '',
            'cancelled' => 'CANCELLED' === $event?->status->value,
            'sort_key' => $occurrence->start->format('YmdHis'),
        ];
    }

    /**
     * @param array<string, mixed> $elementData
     * @return array{DateTimeImmutable, DateTimeImmutable}
     */
    private static function dateRange(array $elementData): array
    {
        $start = new DateTimeImmutable('yesterday' === ($elementData['start_date_choice'] ?? 'today') ? 'yesterday' : 'today');
        $period = (string) ($elementData['period'] ?? 'quarter');
        if ('all' === $period) {
            return [new DateTimeImmutable('1900-01-01'), new DateTimeImmutable('2100-01-01')];
        }

        return [$start, $start->modify(match ($period) {
            'halfayear' => '+6 months',
            'year' => '+1 year',
            'twoyears' => '+2 years',
            'threeyears' => '+3 years',
            default => '+3 months',
        })];
    }

    /**
     * @return list<int>
     */
    private static function ids(mixed $value): array
    {
        $raw = is_array($value) ? $value : (preg_split('/[\s,]+/', (string) $value) ?: []);

        return array_values(array_unique(array_filter(array_map(intval(...), $raw), static fn (int $id): bool => $id > 0)));
    }

    /**
     * @template T
     * @param callable(): list<T> $callback
     * @return list<T>
     */
    private static function safe(callable $callback): array
    {
        try {
            return $callback();
        } catch (Throwable) {
            return []; // etwa während der Installation, bevor die Tabellen existieren
        }
    }

    private static function monthLabel(DateTimeInterface $date): string
    {
        $formatter = new IntlDateFormatter(str_replace('_', '-', \rex_i18n::getLocale()), IntlDateFormatter::NONE, IntlDateFormatter::NONE, null, null, 'LLLL yyyy');

        return (string) $formatter->format($date);
    }
}
