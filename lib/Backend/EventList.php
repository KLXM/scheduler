<?php

declare(strict_types=1);

namespace KLXM\Scheduler\Backend;

use DateTimeImmutable;
use DateTimeZone;
use KLXM\Scheduler\Domain\Event;
use KLXM\Scheduler\Domain\EventTranslation;
use KLXM\Scheduler\Domain\Occurrence;
use KLXM\Scheduler\I18n;
use KLXM\Scheduler\Scheduler;
use KLXM\Scheduler\Orm\MetadataFactory;
use KLXM\Scheduler\Recurrence\Rule;
use KLXM\Scheduler\Security\Access;
use rex_csrf_token;
use rex_url;

/**
 * Terminliste des Backends: ein Eintrag je Termin oder Serie, gefiltert und paginiert in SQL.
 *
 * @internal
 */
final class EventList
{
    private const int PER_PAGE = 30;

    public function __construct(
        private readonly rex_csrf_token $csrf,
    ) {}

    /**
     * Aktuelle Filter als URL-Parameter, damit Aktionen und Editor zur selben Ansicht zurückkehren.
     *
     * @return array<string, string|int>
     */
    public static function filterParams(): array
    {
        return array_filter([
            'q' => rex_request('q', 'string'),
            'calendar' => rex_request('calendar', 'int'),
            'scope' => rex_request('scope', 'string'),
            'p' => rex_request('p', 'int'),
        ]);
    }

    public function render(): string
    {
        $search = trim(rex_request('q', 'string'));
        $calendarId = rex_request('calendar', 'int');
        $scope = in_array(rex_request('scope', 'string'), ['upcoming', 'past', 'all', 'offline'], true) ? rex_request('scope', 'string') : 'upcoming';
        $connection = Scheduler::em()->connection;
        $occurrenceTable = $connection->table(MetadataFactory::for(Occurrence::class)->table);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'))->format('Y-m-d H:i:s');

        $calendars = [];
        foreach (Scheduler::calendars()->all() as $calendar) {
            $calendars[(int) $calendar->id] = $calendar;
        }

        $query = Scheduler::events()->query();
        if ($calendarId > 0) {
            $query->where('calendarId', $calendarId);
        }
        if ('' !== $search) {
            $like = '%' . addcslashes($search, '%_\\') . '%';
            $query->whereRaw(
                'EXISTS (SELECT 1 FROM ' . $connection->table(MetadataFactory::for(EventTranslation::class)->table) . ' t WHERE t.`event_id` = {id} AND (t.`title` LIKE ? OR t.`teaser` LIKE ?))',
                [$like, $like],
            );
        }
        if ('offline' === $scope) {
            $query->where('published', false);
        } elseif ('all' !== $scope) {
            $query->whereRaw(
                ('past' === $scope ? 'NOT ' : '') . 'EXISTS (SELECT 1 FROM ' . $occurrenceTable . ' o WHERE o.`event_id` = {id} AND o.`end_utc` >= ?)',
                [$now],
            );
        }
        $page = $query->orderBy('dtstart', 'upcoming' === $scope ? 'asc' : 'desc')->paginate(max(1, rex_request('p', 'int', 1)), self::PER_PAGE);

        $nextByEvent = $this->nextOccurrences(array_map(static fn (Event $e): int => (int) $e->id, $page->items));
        $clangId = \rex_clang::getCurrentId();
        $dateFormat = new \IntlDateFormatter(\rex_i18n::getLocale(), \IntlDateFormatter::MEDIUM, \IntlDateFormatter::NONE);
        $timeFormat = new \IntlDateFormatter(\rex_i18n::getLocale(), \IntlDateFormatter::NONE, \IntlDateFormatter::SHORT);

        $rows = '';
        foreach ($page->items as $event) {
            $calendar = $calendars[$event->calendarId] ?? null;
            $editable = Access::canEditEvent($event);
            $deletable = Access::canDeleteEvent($event);
            $status = '<i class="rex-icon ' . ($event->published ? 'rex-icon-online' : 'rex-icon-offline') . '"></i> ' . I18n::e($event->published ? 'online' : 'offline');
            $start = $nextByEvent[(int) $event->id] ?? $event->dtstart;
            $when = Html::e((string) $dateFormat->format($start)) . ($event->allDay ? '' : ' <span class="text-muted">' . Html::e((string) $timeFormat->format($start)) . '</span>');
            $editUrl = rex_url::backendPage('scheduler/events', ['func' => 'edit', 'id' => $event->id] + self::filterParams(), false);
            $actionUrl = fn (string $func): string => rex_url::backendPage('scheduler/events', ['func' => $func, 'id' => $event->id] + self::filterParams() + $this->csrf->getUrlParams(), false);

            $rows .= '<tr' . ('CANCELLED' === $event->status->value ? ' class="scheduler-cancelled"' : '') . '>'
                . '<td class="rex-table-icon">' . Html::colorDot($calendar?->color) . '</td>'
                . '<td data-title="' . I18n::e('event_single') . '">' . ($editable ? '<a href="' . Html::e($editUrl) . '">' . Html::e($event->title($clangId)) . '</a>' : Html::e($event->title($clangId)))
                . ($event->isRecurring ? ' <i class="rex-icon fa-repeat scheduler-muted-icon" title="' . Html::e($this->ruleText($event)) . '"></i>' : '') . '</td>'
                . '<td data-title="' . I18n::e('list_next_date') . '">' . $when . '</td>'
                . '<td data-title="' . I18n::e('calendar_single') . '">' . Html::e($calendar?->name) . '</td>'
                . '<td class="rex-table-action">' . ($editable && Access::canPublish()
                    ? '<a class="' . ($event->published ? 'rex-online' : 'rex-offline') . '" href="' . Html::e($actionUrl('toggle')) . '">' . $status . '</a>'
                    : '<span class="' . ($event->published ? 'rex-online' : 'rex-offline') . '">' . $status . '</span>') . '</td>'
                . '<td class="rex-table-action">' . ($editable ? '<a href="' . Html::e($editUrl) . '"><i class="rex-icon rex-icon-edit"></i> ' . I18n::e('action_edit') . '</a>' : '') . '</td>'
                . '<td class="rex-table-action">' . ($editable ? '<a href="' . Html::e($actionUrl('duplicate')) . '"><i class="rex-icon rex-icon-duplicate"></i> ' . I18n::e('action_duplicate') . '</a>' : '') . '</td>'
                . '<td class="rex-table-action">' . ($deletable ? '<a href="' . Html::e($actionUrl('delete')) . '" data-confirm="' . I18n::e('event_delete_confirm') . '"><i class="rex-icon rex-icon-delete"></i> ' . I18n::e('action_delete') . '</a>' : '') . '</td>'
                . '</tr>';
        }
        if ('' === $rows) {
            $rows = '<tr><td colspan="8" class="scheduler-empty">' . I18n::e('list_empty') . '</td></tr>';
        }

        $calendarOptions = array_map(static fn ($c): string => $c->name, $calendars);
        $filter = '<form class="scheduler-filter" method="get" action="' . Html::e(rex_url::backendController()) . '" role="search">'
            . '<input type="hidden" name="page" value="scheduler/events">'
            . Html::input('search', 'q', $search, ['placeholder' => I18n::t('list_search_placeholder'), 'aria-label' => I18n::t('list_search')])
            . Html::select('calendar', $calendarOptions, $calendarId, ['aria-label' => I18n::t('calendar_single')], I18n::t('list_all_calendars'))
            . Html::select('scope', ['upcoming' => I18n::t('list_scope_upcoming'), 'past' => I18n::t('list_scope_past'), 'all' => I18n::t('list_scope_all'), 'offline' => I18n::t('event_awaiting_approval')], $scope, ['aria-label' => I18n::t('event_period')])
            . '<button class="btn btn-default" type="submit"><i class="rex-icon fa-magnifying-glass"></i> ' . I18n::e('list_filter') . '</button>'
            . '</form>';

        $table = '<table class="table table-striped table-hover"><thead><tr>'
            . '<th class="rex-table-icon"><a href="' . Html::e(rex_url::backendPage('scheduler/events', ['func' => 'add'], false)) . '" title="' . I18n::e('event_add') . '"><i class="rex-icon rex-icon-add"></i></a></th>'
            . '<th>' . I18n::e('event_single') . '</th><th>' . I18n::e('list_next_date') . '</th><th>' . I18n::e('calendar_single') . '</th><th class="rex-table-action" colspan="4">' . I18n::e('functions') . '</th></tr></thead><tbody>' . $rows . '</tbody></table>';

        return $filter . Html::section(I18n::e('events') . ' <small>' . $page->total . '</small>', $table, class: 'default') . $this->pager($page->page, $page->pages);
    }

    /**
     * Nächstes (sonst letztes) Vorkommen je Termin, in einer Abfrage.
     *
     * @param list<int> $eventIds
     * @return array<int, DateTimeImmutable>
     */
    private function nextOccurrences(array $eventIds): array
    {
        if ([] === $eventIds) {
            return [];
        }
        $connection = Scheduler::em()->connection;
        $table = $connection->table(MetadataFactory::for(Occurrence::class)->table);
        $placeholders = implode(', ', array_fill(0, count($eventIds), '?'));
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'))->format('Y-m-d H:i:s');

        $rows = $connection->fetchAll(
            'SELECT `event_id`, COALESCE(MIN(CASE WHEN `end_utc` >= ? THEN `start` END), MAX(`start`)) AS `next`, MAX(`timezone`) AS `timezone` FROM ' . $table
            . ' WHERE `event_id` IN (' . $placeholders . ') GROUP BY `event_id`',
            [$now, ...$eventIds],
        );

        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['event_id']] = new DateTimeImmutable((string) $row['next'], new DateTimeZone((string) $row['timezone']));
        }

        return $result;
    }

    private function ruleText(Event $event): string
    {
        try {
            return null !== $event->rrule ? Rule::parse($event->rrule)->toText(substr(\rex_i18n::getLocale(), 0, 2), $event->dtstart) : I18n::t('list_extra_dates');
        } catch (\Throwable) {
            return (string) $event->rrule;
        }
    }

    private function pager(int $current, int $pages): string
    {
        if ($pages <= 1) {
            return '';
        }
        $html = '<nav class="scheduler-pager" aria-label="' . I18n::e('list_pages') . '"><ul class="pagination">';
        for ($i = 1; $i <= $pages; ++$i) {
            if ($pages > 12 && $i > 2 && $i < $pages - 1 && abs($i - $current) > 2) {
                $html .= (3 === $i || $i === $pages - 2) ? '<li class="disabled"><span>…</span></li>' : '';
                continue;
            }
            $url = rex_url::backendPage('scheduler/events', ['p' => $i] + self::filterParams(), false);
            $html .= sprintf('<li%s><a href="%s"%s>%d</a></li>', $i === $current ? ' class="active"' : '', Html::e($url), $i === $current ? ' aria-current="page"' : '', $i);
        }

        return $html . '</ul></nav>';
    }
}
