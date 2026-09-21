<?php

declare(strict_types=1);

use KLXM\Scheduler\Backend\Flash;
use KLXM\Scheduler\Backend\Html;
use KLXM\Scheduler\I18n;
use KLXM\Scheduler\Scheduler;
use KLXM\Scheduler\Settings;

$csrf = rex_csrf_token::factory('scheduler_settings');
$addon = rex_addon::get('scheduler');

if ('post' === rex_request_method()) {
    if (!$csrf->isValid()) {
        echo rex_view::error(rex_i18n::msg('csrf_token_invalid'));
    } elseif ('reindex' === rex_post('action', 'string')) {
        $result = Scheduler::indexer()->reindexAll();
        Flash::success(I18n::t('settings_reindexed', $result['events'], $result['occurrences']));
        rex_response::sendRedirect(rex_url::currentBackendPage([], false));
    } else {
        $timezone = rex_post('default_timezone', 'string');
        $addon->setConfig('default_timezone', in_array($timezone, DateTimeZone::listIdentifiers(), true) ? $timezone : Settings::DEFAULTS['default_timezone']);
        $addon->setConfig('default_all_day', rex_post('default_all_day', 'bool'));
        $addon->setConfig('week_starts_on', rex_post('week_starts_on', 'int') % 7);
        $addon->setConfig('horizon_back_months', max(0, rex_post('horizon_back_months', 'int')));
        $addon->setConfig('horizon_ahead_months', min(240, max(1, rex_post('horizon_ahead_months', 'int'))));
        $addon->setConfig('feed_months_back', max(0, rex_post('feed_months_back', 'int')));
        $addon->setConfig('feed_months_ahead', max(1, rex_post('feed_months_ahead', 'int')));
        $addon->setConfig('editor_class', trim(rex_post('editor_class', 'string')));
        $addon->setConfig('editor_profile', trim(rex_post('editor_profile', 'string')));
        Flash::success(I18n::t('settings_saved'));
        rex_response::sendRedirect(rex_url::currentBackendPage([], false));
    }
}

echo Flash::render();
$settings = Settings::fromRedaxo();
$timezones = array_combine(DateTimeZone::listIdentifiers(), DateTimeZone::listIdentifiers());
$months = static fn (string $name, int $value, string $id): string => '<div class="scheduler-period-row">' . Html::input('number', $name, (string) $value, ['id' => $id, 'min' => 0, 'max' => 240]) . ' ' . I18n::e('settings_months') . '</div>';

$general = Html::field(I18n::t('settings_timezone'), Html::select('default_timezone', $timezones, $settings->defaultTimezone(), ['id' => 'scheduler-tz']), 'scheduler-tz', I18n::t('settings_timezone_help'))
    . Html::field(I18n::t('settings_new_events'), Html::toggle('default_all_day', $settings->defaultAllDay(), I18n::t('settings_all_day_label'), 'scheduler-allday'), 'scheduler-allday')
    . Html::field(I18n::t('settings_week_start'), Html::select('week_starts_on', [1 => I18n::t('weekday_mo'), 0 => I18n::t('weekday_su'), 6 => I18n::t('weekday_sa')], $settings->weekStartsOn(), ['id' => 'scheduler-week']), 'scheduler-week');

$editor = Html::field(I18n::t('settings_editor_class'), Html::input('text', 'editor_class', $settings->editorClass(), ['id' => 'scheduler-editor-class', 'placeholder' => 'tiny-editor']), 'scheduler-editor-class', I18n::t('settings_editor_class_help'))
    . Html::field(I18n::t('settings_editor_profile'), Html::input('text', 'editor_profile', $settings->editorProfile(), ['id' => 'scheduler-editor-profile']), 'scheduler-editor-profile');

$index = Html::field(I18n::t('settings_horizon'), $months('horizon_ahead_months', $settings->horizonAheadMonths(), 'scheduler-ahead'), 'scheduler-ahead', I18n::t('settings_horizon_help'))
    . Html::field(I18n::t('settings_lookback'), $months('horizon_back_months', $settings->horizonBackMonths(), 'scheduler-back'), 'scheduler-back', I18n::t('settings_lookback_help'))
    . Html::field(I18n::t('settings_feed_back'), $months('feed_months_back', $settings->feedMonthsBack(), 'scheduler-feed-back'), 'scheduler-feed-back', I18n::t('settings_feed_back_help'))
    . Html::field(I18n::t('settings_feed_ahead'), $months('feed_months_ahead', $settings->feedMonthsAhead(), 'scheduler-feed-ahead'), 'scheduler-feed-ahead');

echo '<form method="post" action="' . Html::e(rex_url::currentBackendPage([], false)) . '">' . $csrf->getHiddenField()
    . Html::section(I18n::e('settings_general'), $general) . Html::section(I18n::e('settings_editor'), $editor) . Html::section(I18n::e('settings_index'), $index)
    . '<div class="scheduler-form-actions"><button class="btn btn-save" type="submit">' . I18n::e('save') . '</button>'
    . '<button class="btn btn-default scheduler-push" type="submit" name="action" value="reindex" formnovalidate><i class="rex-icon fa-rotate"></i> ' . I18n::e('settings_reindex') . '</button></div></form>';
