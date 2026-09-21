<?php

declare(strict_types=1);

use KLXM\Scheduler\Backend\CustomFields;
use KLXM\Scheduler\Backend\Flash;
use KLXM\Scheduler\Backend\Html;
use KLXM\Scheduler\Domain\Calendar;
use KLXM\Scheduler\Domain\Enum\SchemaTarget;
use KLXM\Scheduler\I18n;
use KLXM\Scheduler\Scheduler;
use KLXM\Scheduler\Service\ValidationException;

$func = rex_request('func', 'string');
$id = rex_request('id', 'int');
$csrf = rex_csrf_token::factory('scheduler_calendars');
$repository = Scheduler::calendars();
$listUrl = rex_url::backendPage('scheduler/calendars', [], false);

echo Flash::render();

if ('delete' === $func) {
    $calendar = $repository->find($id);
    try {
        if (!$csrf->isValid()) {
            Flash::error(rex_i18n::msg('csrf_token_invalid'));
        } elseif (null !== $calendar) {
            $repository->delete($calendar);
            Flash::success(I18n::t('calendar_deleted'));
        }
    } catch (ValidationException $e) {
        Flash::error($e->getMessage());
    }
    rex_response::sendRedirect($listUrl);
}

if ('add' === $func || 'edit' === $func) {
    $calendar = 'edit' === $func ? $repository->find($id) : new Calendar();
    if (null === $calendar) {
        echo rex_view::error(I18n::e('calendar_not_found'));

        return;
    }
    if (null === $calendar->id) {
        $calendar->timezone = Scheduler::settings()->defaultTimezone();
    }
    $errors = [];
    $clangs = CustomFields::clangs();
    $firstClang = array_key_first($clangs);

    if ('post' === rex_request_method()) {
        if (!$csrf->isValid()) {
            $errors['csrf'] = rex_i18n::msg('csrf_token_invalid');
        } else {
            $names = array_map(static fn ($n): string => trim((string) $n), rex_post('name', 'array', []));
            $calendar->name = $names[$firstClang] ?? '';
            unset($names[$firstClang]);
            $calendar->nameTranslations = array_filter($names, static fn (string $n): bool => '' !== $n && $n !== $calendar->name);
            $calendar->slug = trim(rex_post('slug', 'string'));
            $calendar->color = 1 === preg_match('/^#[0-9a-fA-F]{6}$/', rex_post('color', 'string')) ? rex_post('color', 'string') : $calendar->color;
            $calendar->timezone = rex_post('timezone', 'string');
            $description = trim(rex_post('description', 'string'));
            $calendar->description = '' === $description ? null : $description;
            $calendar->active = rex_post('active', 'bool');
            $calendar->publicFeed = rex_post('public_feed', 'bool');
            $calendar->davEnabled = rex_post('dav_enabled', 'bool');
            $calendar->priority = rex_post('priority', 'int');
            [$calendar->custom, $customErrors] = CustomFields::process(SchemaTarget::Calendar, $calendar->custom);
            $errors += $customErrors;

            if ([] === $errors) {
                try {
                    $repository->save($calendar);
                    Flash::success(I18n::t('calendar_saved'));
                    rex_response::sendRedirect(rex_post('save_and_close', 'bool') ? $listUrl : rex_url::backendPage('scheduler/calendars', ['func' => 'edit', 'id' => $calendar->id], false));
                } catch (ValidationException $e) {
                    $errors += $e->errors;
                }
            }
        }
        if ([] !== $errors) {
            echo rex_view::error(Html::e(implode(' ', array_unique($errors))));
        }
    }

    $nameInputs = '';
    foreach ($clangs as $clangId => $clangName) {
        $nameInputs .= sprintf(
            '<div data-lang="%s" data-lang-id="%d">%s</div>',
            Html::e($clangName),
            $clangId,
            Html::input('text', "name[$clangId]", $clangId === $firstClang ? $calendar->name : ($calendar->nameTranslations[$clangId] ?? ''), ['id' => "scheduler-name-$clangId", 'required' => $clangId === $firstClang, 'maxlength' => 191]),
        );
    }
    $timezones = array_combine(DateTimeZone::listIdentifiers(), DateTimeZone::listIdentifiers());
    $feedUrl = null !== $calendar->id ? rex::getServer() . 'index.php?rex-api-call=scheduler_feed&calendar=' . rawurlencode($calendar->slug) : null;

    $body = Html::field(I18n::t('name'), count($clangs) > 1 ? '<scheduler-lang-field>' . $nameInputs . '</scheduler-lang-field>' : $nameInputs, "scheduler-name-$firstClang", error: $errors['name'] ?? null, required: true)
        . Html::field(I18n::t('calendar_color'), Html::input('color', 'color', $calendar->color, ['id' => 'scheduler-color', 'class' => 'scheduler-color']), 'scheduler-color', I18n::t('calendar_color_help'))
        . Html::field(I18n::t('timezone'), Html::select('timezone', $timezones, $calendar->timezone, ['id' => 'scheduler-timezone']), 'scheduler-timezone', I18n::t('calendar_timezone_help'), $errors['timezone'] ?? null)
        . Html::field(I18n::t('description'), '<textarea class="form-control" rows="3" name="description" id="scheduler-description">' . Html::e($calendar->description) . '</textarea>', 'scheduler-description')
        . Html::field(I18n::t('calendar_slug'), Html::input('text', 'slug', $calendar->slug, ['id' => 'scheduler-slug', 'maxlength' => 100, 'pattern' => '[a-z0-9\-]*']), 'scheduler-slug', I18n::t('calendar_slug_help'))
        . Html::field(I18n::t('calendar_priority'), Html::input('number', 'priority', (string) $calendar->priority, ['id' => 'scheduler-priority']), 'scheduler-priority', I18n::t('calendar_priority_help'))
        . Html::field(I18n::t('calendar_website'), Html::toggle('active', $calendar->active, I18n::t('calendar_active_label'), 'scheduler-active'), 'scheduler-active', I18n::t('calendar_active_help'))
        . Html::field(I18n::t('calendar_feed'), Html::toggle('public_feed', $calendar->publicFeed, I18n::t('calendar_feed_label'), 'scheduler-public-feed')
            . (null !== $feedUrl ? '<p class="help-block"><code>' . Html::e($feedUrl) . '</code></p>' : ''), 'scheduler-public-feed', I18n::t('calendar_feed_help'))
        . Html::field(I18n::t('calendar_dav'), Html::toggle('dav_enabled', $calendar->davEnabled, I18n::t('calendar_dav_label'), 'scheduler-dav-enabled'), 'scheduler-dav-enabled', I18n::t('calendar_dav_help'));

    echo '<form action="' . Html::e(rex_url::backendPage('scheduler/calendars', array_filter(['func' => $func, 'id' => $calendar->id]), false)) . '" method="post">' . $csrf->getHiddenField()
        . Html::section(I18n::e(null === $calendar->id ? 'calendar_add' : 'calendar_edit'), $body)
        . CustomFields::render(SchemaTarget::Calendar, $calendar->custom, $errors)
        . Html::formActions($listUrl) . '</form>';

    return;
}

$counts = [];
foreach (Scheduler::em()->connection->fetchAll('SELECT `calendar_id`, COUNT(*) AS c FROM ' . Scheduler::em()->connection->table('scheduler_event') . ' GROUP BY `calendar_id`') as $row) {
    $counts[(int) $row['calendar_id']] = (int) $row['c'];
}

$rows = '';
foreach ($repository->all() as $calendar) {
    $editUrl = rex_url::backendPage('scheduler/calendars', ['func' => 'edit', 'id' => $calendar->id], false);
    $count = $counts[(int) $calendar->id] ?? 0;
    $rows .= '<tr><td class="rex-table-icon">' . Html::colorDot($calendar->color) . '</td>'
        . '<td data-title="' . I18n::e('name') . '"><a href="' . Html::e($editUrl) . '">' . Html::e($calendar->name) . '</a></td>'
        . '<td data-title="' . I18n::e('events') . '"><a href="' . Html::e(rex_url::backendPage('scheduler/events', ['calendar' => $calendar->id, 'scope' => 'all'], false)) . '">' . $count . '</a></td>'
        . '<td data-title="' . I18n::e('timezone') . '">' . Html::e($calendar->timezone) . '</td>'
        . '<td data-title="' . I18n::e('status') . '">' . Html::activeState($calendar->active)
        . ($calendar->publicFeed ? ' <i class="rex-icon fa-rss scheduler-muted-icon" title="' . I18n::e('calendar_feed_label') . '"></i>' : '')
        . ($calendar->davEnabled ? ' <i class="rex-icon fa-mobile-screen scheduler-muted-icon" title="' . I18n::e('calendar_dav_label') . '"></i>' : '') . '</td>'
        . '<td class="rex-table-action"><a href="' . Html::e($editUrl) . '"><i class="rex-icon rex-icon-edit"></i> ' . I18n::e('action_edit') . '</a></td>'
        . '<td class="rex-table-action">' . (0 === $count ? '<a href="' . Html::e(rex_url::backendPage('scheduler/calendars', ['func' => 'delete', 'id' => $calendar->id] + $csrf->getUrlParams(), false)) . '" data-confirm="' . I18n::e('calendar_delete_confirm') . '"><i class="rex-icon rex-icon-delete"></i> ' . I18n::e('action_delete') . '</a>' : '<span class="text-muted" title="' . I18n::e('calendar_delete_blocked') . '">' . I18n::e('action_delete') . '</span>') . '</td></tr>';
}

echo Html::section(I18n::e('calendars'), '<table class="table table-striped table-hover"><thead><tr>'
    . '<th class="rex-table-icon"><a href="' . Html::e(rex_url::backendPage('scheduler/calendars', ['func' => 'add'], false)) . '" title="' . I18n::e('calendar_add') . '"><i class="rex-icon rex-icon-add"></i></a></th>'
    . '<th>' . I18n::e('name') . '</th><th>' . I18n::e('events') . '</th><th>' . I18n::e('timezone') . '</th><th>' . I18n::e('status') . '</th><th class="rex-table-action" colspan="2">' . I18n::e('functions') . '</th></tr></thead><tbody>'
    . ('' !== $rows ? $rows : '<tr><td colspan="7" class="scheduler-empty">' . I18n::e('calendar_none') . '</td></tr>') . '</tbody></table>', class: 'default');
