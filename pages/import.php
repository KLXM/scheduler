<?php

declare(strict_types=1);

use KLXM\Scheduler\Backend\Flash;
use KLXM\Scheduler\Backend\Html;
use KLXM\Scheduler\I18n;
use KLXM\Scheduler\Scheduler;
use KLXM\Scheduler\Ical\InvalidIcalException;
use KLXM\Scheduler\Import\FetchException;
use KLXM\Scheduler\Import\IcsFetcher;
use KLXM\Scheduler\Import\IcsImporter;
use KLXM\Scheduler\Domain\Subscription;
use KLXM\Scheduler\Import\ImportReport;
use KLXM\Scheduler\Import\SubscriptionService;
use KLXM\Scheduler\Import\Legacy\CodeScanner;
use KLXM\Scheduler\Import\Legacy\LegacyImporter;

$csrf = rex_csrf_token::factory('scheduler_import');
$action = rex_post('action', 'string');
$report = null;
$subscriptions = new SubscriptionService();

$clangIds = array_values(array_unique([rex_clang::getStartId(), ...rex_clang::getAllIds()]));
$legacy = new LegacyImporter(Scheduler::em()->connection, $clangIds, Scheduler::settings()->defaultTimezone(), rex_path::addonData('forcal', 'definitions'));

$renderReport = static function (ImportReport $report): string {
    $html = $report->dryRun ? rex_view::info(I18n::e('import_dry_run_note')) : rex_view::success(I18n::e('import_done'));
    if ([] !== $report->counts) {
        $html .= '<table class="table table-condensed scheduler-report"><tbody>';
        foreach ($report->counts as $label => $count) {
            $html .= '<tr><th scope="row">' . Html::e($label) . '</th><td>' . $count . '</td></tr>';
        }
        $html .= '</tbody></table>';
    }
    if ([] !== $report->messages) {
        $html .= '<ul class="scheduler-report-messages">';
        foreach ($report->messages as $message) {
            $html .= sprintf('<li class="scheduler-report-%s"><strong>%s</strong> %s</li>', $message['level'], Html::e($message['subject']), Html::e($message['message']));
        }
        $html .= '</ul>';
    }

    return Html::section(I18n::e('import_result'), $html, class: 'default');
};

if ('post' === rex_request_method()) {
    if (!$csrf->isValid()) {
        echo rex_view::error(rex_i18n::msg('csrf_token_invalid'));
    } elseif (in_array($action, ['legacy_dry', 'legacy_run'], true)) {
        $report = $legacy->run('legacy_dry' === $action);
    } elseif (in_array($action, ['sub_sync', 'sub_toggle', 'sub_delete'], true)) {
        $subscription = $subscriptions->repository()->find(rex_post('subscription_id', 'int'));
        if (null !== $subscription) {
            if ('sub_sync' === $action) {
                $report = $subscriptions->sync($subscription);
                if (null === $report) {
                    echo rex_view::error(Html::e((string) $subscription->lastMessage));
                }
            } elseif ('sub_toggle' === $action) {
                $subscription->active = !$subscription->active;
                $subscriptions->repository()->save($subscription);
                Flash::success(I18n::t($subscription->active ? 'sub_resumed' : 'sub_paused'));
                rex_response::sendRedirect(rex_url::currentBackendPage([], false));
            } else {
                $subscriptions->repository()->delete($subscription);
                Flash::success(I18n::t('sub_ended'));
                rex_response::sendRedirect(rex_url::currentBackendPage([], false));
            }
        }
    } elseif (in_array($action, ['ics_dry', 'ics_run'], true)) {
        $calendar = Scheduler::calendars()->find(rex_post('calendar_id', 'int'));
        $file = rex_files('ics', 'array', []);
        $url = trim(rex_post('ics_url', 'string'));
        $ics = '';
        $fetchError = null;
        if (UPLOAD_ERR_OK === ($file['error'] ?? UPLOAD_ERR_NO_FILE) && is_uploaded_file((string) $file['tmp_name'])) {
            $ics = (string) file_get_contents((string) $file['tmp_name']);
        } elseif ('' !== $url) {
            try {
                $ics = new IcsFetcher()->fetch($url);
            } catch (FetchException $e) {
                $fetchError = $e->getMessage();
            }
        }

        if (null === $calendar) {
            echo rex_view::error(I18n::e('import_choose_calendar'));
        } elseif (null !== $fetchError) {
            echo rex_view::error(Html::e($fetchError));
        } elseif ('' === $ics) {
            echo rex_view::error(I18n::e('import_no_source'));
        } else {
            try {
                $report = new IcsImporter()->import($ics, $calendar, rex_clang::getStartId(), 'ics_dry' === $action, '' !== $url ? $url : null, '' !== $url && rex_post('sync', 'bool'));

                // Optional als Abo speichern: Der Cronjob gleicht die Adresse dann regelmäßig ab.
                if ('ics_run' === $action && '' !== $url && rex_post('subscribe', 'bool') && !$report->hasErrors) {
                    $existing = $subscriptions->repository()->query()->where('url', $url)->where('calendarId', (int) $calendar->id)->first();
                    $subscription = $existing ?? new Subscription();
                    $subscription->calendarId = (int) $calendar->id;
                    $subscription->url = $url;
                    $subscription->clangId = rex_clang::getStartId();
                    $subscription->removeMissing = rex_post('sync', 'bool');
                    $subscription->intervalMinutes = max(60, rex_post('interval', 'int', 1440));
                    $subscription->lastSyncAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
                    $subscription->lastStatus = 'ok';
                    $subscription->lastMessage = I18n::t('sub_created_message');
                    $subscriptions->repository()->save($subscription);
                    $report->info(I18n::t('sub_single'), I18n::t(null === $existing ? 'sub_created' : 'sub_updated'));
                }
            } catch (InvalidIcalException $e) {
                echo rex_view::error(Html::e($e->getMessage()));
            }
        }
    }
}

echo Flash::render();
if (null !== $report) {
    echo $renderReport($report);
}

$calendars = [];
foreach (Scheduler::calendars()->all() as $calendar) {
    $calendars[(int) $calendar->id] = $calendar->name;
}

// ---------- ICS ----------
$intervals = [60 => I18n::t('sub_interval_hourly'), 360 => I18n::t('sub_interval_6h'), 1440 => I18n::t('sub_interval_daily'), 10080 => I18n::t('sub_interval_weekly')];
$icsBody = '<p class="scheduler-lead">' . I18n::e('import_ics_intro') . '</p>'
    . Html::field(I18n::t('import_target'), Html::select('calendar_id', $calendars, rex_post('calendar_id', 'int'), ['id' => 'scheduler-import-calendar', 'required' => true], [] === $calendars ? I18n::t('import_create_calendar_first') : null), 'scheduler-import-calendar', required: true)
    . Html::field(I18n::t('import_file'), '<input type="file" name="ics" id="scheduler-ics" accept=".ics,text/calendar" class="form-control">', 'scheduler-ics')
    . Html::field(I18n::t('import_url'), Html::input('text', 'ics_url', rex_post('ics_url', 'string'), ['id' => 'scheduler-ics-url', 'placeholder' => 'https://example.org/calendar.ics']), 'scheduler-ics-url', I18n::t('import_url_help'))
    . Html::field(I18n::t('import_sync'), Html::toggle('sync', rex_post('sync', 'bool'), I18n::t('import_sync_label'), 'scheduler-sync'), 'scheduler-sync', I18n::t('import_sync_help'))
    . Html::field(I18n::t('sub_single'), Html::toggle('subscribe', rex_post('subscribe', 'bool'), I18n::t('sub_label'), 'scheduler-subscribe')
        . '<div class="scheduler-period-row scheduler-subscribe-interval">' . Html::select('interval', $intervals, rex_post('interval', 'int', 1440), ['aria-label' => I18n::t('sub_interval')]) . '</div>',
        'scheduler-subscribe', I18n::t('sub_help'));
echo '<form method="post" enctype="multipart/form-data" action="' . Html::e(rex_url::currentBackendPage([], false)) . '">' . $csrf->getHiddenField()
    . Html::section(I18n::e('import_ics_title'), $icsBody, '<button class="btn btn-default" type="submit" name="action" value="ics_dry">' . I18n::e('import_dry_run') . '</button> <button class="btn btn-save" type="submit" name="action" value="ics_run">' . I18n::e('import_run') . '</button>')
    . '</form>';

// ---------- Abos ----------
$subscriptionRows = '';
$dateFormat = new IntlDateFormatter(rex_i18n::getLocale(), IntlDateFormatter::MEDIUM, IntlDateFormatter::SHORT);
foreach ($subscriptions->all() as $subscription) {
    $button = static fn (string $action, string $label, string $class = 'btn-default', string $confirm = ''): string => '<form method="post" action="' . Html::e(rex_url::currentBackendPage([], false)) . '" class="scheduler-inline-form">' . $csrf->getHiddenField()
        . '<input type="hidden" name="action" value="' . $action . '"><input type="hidden" name="subscription_id" value="' . (int) $subscription->id . '">'
        . '<button class="btn ' . $class . ' btn-xs" type="submit"' . ('' !== $confirm ? ' data-confirm="' . Html::e($confirm) . '"' : '') . '>' . $label . '</button></form>';
    $state = !$subscription->active ? '<span class="text-muted">' . I18n::e('sub_state_paused') . '</span>'
        : ('error' === $subscription->lastStatus ? '<span class="text-danger">' . I18n::e('sub_state_error') . '</span>' : '<span class="rex-online">' . I18n::e('active') . '</span>');
    $subscriptionRows .= '<tr><td>' . Html::e($calendars[$subscription->calendarId] ?? I18n::t('sub_calendar_gone')) . '</td>'
        . '<td class="scheduler-break"><a href="' . Html::e($subscription->url) . '" rel="noopener" target="_blank">' . Html::e($subscription->url) . '</a></td>'
        . '<td>' . Html::e($intervals[$subscription->intervalMinutes] ?? I18n::t('sub_interval_minutes', $subscription->intervalMinutes)) . ($subscription->removeMissing ? '<br><small class="text-muted">' . I18n::e('sub_with_removal') . '</small>' : '') . '</td>'
        . '<td>' . $state . '<br><small class="text-muted">' . Html::e(null !== $subscription->lastSyncAt ? (string) $dateFormat->format($subscription->lastSyncAt) : I18n::t('sub_never')) . ': ' . Html::e((string) $subscription->lastMessage) . '</small></td>'
        . '<td class="rex-table-action">' . $button('sub_sync', I18n::e('sub_sync_now')) . ' ' . $button('sub_toggle', I18n::e($subscription->active ? 'sub_pause' : 'sub_resume')) . ' '
        . $button('sub_delete', I18n::e('sub_end'), 'btn-delete', I18n::t('sub_end_confirm')) . '</td></tr>';
}
if ('' !== $subscriptionRows) {
    echo Html::section(I18n::e('sub_title'), '<table class="table table-striped"><thead><tr><th>' . I18n::e('calendar_single') . '</th><th>' . I18n::e('sub_address') . '</th><th>' . I18n::e('sub_interval') . '</th><th>' . I18n::e('sub_last_sync') . '</th><th></th></tr></thead><tbody>' . $subscriptionRows . '</tbody></table>', class: 'default');
}

// ---------- forcal ----------
if ($legacy->isAvailable()) {
    $counts = $legacy->sourceCounts();
    $legacyBody = '<p>' . I18n::t('legacy_found', $counts['entries'], $counts['categories'], $counts['venues']) . '</p>'
        . '<ul><li>' . I18n::e('legacy_point_mapping') . '</li><li>' . I18n::e('legacy_point_recurrence') . '</li><li>' . I18n::e('legacy_point_repeatable') . '</li></ul>';
    echo '<form method="post" action="' . Html::e(rex_url::currentBackendPage([], false)) . '">' . $csrf->getHiddenField()
        . Html::section(I18n::e('legacy_title'), $legacyBody, '<button class="btn btn-default" type="submit" name="action" value="legacy_dry">' . I18n::e('import_dry_run') . '</button> <button class="btn btn-save" type="submit" name="action" value="legacy_run" data-confirm="' . I18n::e('legacy_confirm') . '">' . I18n::e('legacy_run') . '</button>')
        . '</form>';

    $findings = new CodeScanner(Scheduler::em()->connection)->scan();
    $rows = '';
    foreach ($findings as $finding) {
        $rows .= sprintf(
            '<tr><td><a href="%s">%s</a><br><small class="text-muted">%s, ' . I18n::e('scanner_line') . ' %d</small></td><td><code>%s</code></td><td><code>%s</code></td></tr>',
            Html::e($finding['url']),
            Html::e($finding['name']),
            Html::e($finding['where']),
            $finding['line'],
            Html::e($finding['code']),
            Html::e($finding['hint']),
        );
    }
    echo Html::section(
        I18n::e('scanner_title') . ' <small>' . count($findings) . '</small>',
        '' === $rows
            ? '<p class="scheduler-empty">' . I18n::e('scanner_empty') . '</p>'
            : '<table class="table table-striped"><thead><tr><th>' . I18n::e('scanner_where') . '</th><th>' . I18n::e('scanner_old') . '</th><th>' . I18n::e('scanner_new') . '</th></tr></thead><tbody>' . $rows . '</tbody></table>',
        class: 'default',
    );
}
