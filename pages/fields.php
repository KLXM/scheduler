<?php

declare(strict_types=1);

use KLXM\Scheduler\Api\BackendApi;
use KLXM\Scheduler\Backend\Flash;
use KLXM\Scheduler\Backend\Html;
use KLXM\Scheduler\Domain\Enum\SchemaTarget;
use KLXM\Scheduler\Field\Schema;
use KLXM\Scheduler\I18n;
use KLXM\Scheduler\Scheduler;
use KLXM\Scheduler\Service\ValidationException;

$target = SchemaTarget::tryFrom(rex_request('target', 'string')) ?? SchemaTarget::Event;
$csrf = rex_csrf_token::factory('scheduler_fields');
$service = Scheduler::schemas();
$pageUrl = static fn (array $params = []): string => rex_url::backendPage('scheduler/fields', ['target' => $target->value] + $params, false);
$definition = null;

// ---------- Export ----------
if ('export' === rex_request('func', 'string')) {
    rex_response::cleanOutputBuffers();
    rex_response::setHeader('Content-Disposition', 'attachment; filename="scheduler-fields-' . $target->value . '.json"');
    rex_response::sendContent($service->exportJson($target), 'application/json; charset=utf-8');
    exit;
}

if ('post' === rex_request_method()) {
    if (!$csrf->isValid()) {
        echo rex_view::error(rex_i18n::msg('csrf_token_invalid'));
    } else {
        try {
            if ('import' === rex_post('action', 'string')) {
                $file = rex_files('schema', 'array', []);
                $json = UPLOAD_ERR_OK === ($file['error'] ?? UPLOAD_ERR_NO_FILE) ? (string) file_get_contents((string) $file['tmp_name']) : '';
                if ('' === $json) {
                    throw new ValidationException(['schema' => I18n::t('fields_import_missing')]);
                }
                Flash::success(I18n::t($service->importJson($json, I18n::t('fields_note_import')) ? 'fields_imported' : 'fields_import_unchanged'));
                rex_response::sendRedirect($pageUrl());
            }

            if ('restore' === rex_post('action', 'string')) {
                foreach ($service->history($target, 100) as $record) {
                    if ($record->id === rex_post('version_id', 'int')) {
                        $service->publish($target, Schema::fromArray($record->definition), note: I18n::t('fields_note_restored', $record->version));
                        Flash::success(I18n::t('fields_restored', $record->version));
                    }
                }
                rex_response::sendRedirect($pageUrl());
            }

            $definition = json_decode(rex_post('definition', 'string', '{}'), true, 64, JSON_THROW_ON_ERROR);
            $renames = json_decode(rex_post('renames', 'string', '{}'), true, 8, JSON_THROW_ON_ERROR);
            $record = $service->publish(
                $target,
                Schema::fromArray(is_array($definition) ? $definition : []),
                is_array($renames) ? array_filter($renames, is_string(...)) : [],
                '' !== trim(rex_post('note', 'string')) ? trim(rex_post('note', 'string')) : null,
            );
            Flash::success(I18n::t('fields_published', $record->version));
            rex_response::sendRedirect($pageUrl());
        } catch (ValidationException $e) {
            echo rex_view::error('<ul><li>' . implode('</li><li>', array_map(Html::e(...), $e->errors)) . '</li></ul>');
        } catch (JsonException) {
            echo rex_view::error(I18n::e('fields_unreadable'));
        }
    }
}

echo Flash::render();

// ---------- Zielauswahl ----------
$labels = [SchemaTarget::Event->value => I18n::t('events'), SchemaTarget::Calendar->value => I18n::t('calendar'), SchemaTarget::Location->value => I18n::t('locations')];
echo '<nav class="scheduler-target-nav" aria-label="' . I18n::e('fields_target') . '"><ul class="nav nav-pills">';
foreach ($labels as $value => $label) {
    printf('<li%s><a href="%s">%s</a></li>', $value === $target->value ? ' class="active"' : '', Html::e(rex_url::backendPage('scheduler/fields', ['target' => $value], false)), Html::e($label));
}
echo '</ul></nav>';

$types = [];
foreach ($service->types->all() as $key => $type) {
    $types[$key] = ['label' => $type->label(), 'icon' => $type->icon(), 'options' => $type->options(), 'allowedInRepeater' => $type->allowedInRepeater()];
}

$core = SchemaTarget::Event === $target
    ? '<p class="scheduler-core-note"><i class="rex-icon fa-lock"></i> ' . I18n::e('fields_core_note') . '</p>'
    : '';

$json = json_encode($definition ?? $service->active($target)->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
$builder = sprintf(
    '<scheduler-field-builder api="%s" target="%s" types="%s" reserved="%s"><input type="hidden" name="definition" value="%s"><input type="hidden" name="renames" value="{}"></scheduler-field-builder>',
    Html::e(rex_url::backendPage('scheduler/fields', BackendApi::getUrlParams(), false)),
    Html::e($target->value),
    Html::e(json_encode($types, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)),
    Html::e(json_encode($service->reservedNames($target), JSON_THROW_ON_ERROR)),
    Html::e($json),
);

$record = $service->activeRecord($target);
echo '<form method="post" action="' . Html::e($pageUrl()) . '">' . $csrf->getHiddenField()
    . Html::section(
        I18n::e('fields_title', $labels[$target->value]) . (null !== $record ? ' <small>' . I18n::e('fields_version', $record->version) . '</small>' : ''),
        $core . $builder,
        '<div class="scheduler-publish">' . Html::input('text', 'note', '', ['placeholder' => I18n::t('fields_note_placeholder'), 'aria-label' => I18n::t('fields_note'), 'maxlength' => 500])
        . '<button class="btn btn-save" type="submit"><i class="rex-icon fa-cloud-arrow-up"></i> ' . I18n::e('fields_publish') . '</button></div>',
    ) . '</form>';

// ---------- Versionen, Export, Import ----------
$rows = '';
$dateFormat = new IntlDateFormatter(rex_i18n::getLocale(), IntlDateFormatter::MEDIUM, IntlDateFormatter::SHORT);
foreach ($service->history($target) as $version) {
    $rows .= sprintf(
        '<tr><td>%d%s</td><td>%s</td><td>%s</td><td>%d</td><td>%s</td><td class="rex-table-action">%s</td></tr>',
        $version->version,
        $version->active ? ' <span class="label label-success">' . I18n::e('active') . '</span>' : '',
        Html::e(null !== $version->createdAt ? (string) $dateFormat->format($version->createdAt) : ''),
        Html::e($version->createdBy),
        count(Schema::fromArray($version->definition)->fields()),
        Html::e($version->note),
        $version->active ? '' : '<form method="post" action="' . Html::e($pageUrl()) . '" class="scheduler-inline-form">' . $csrf->getHiddenField()
            . '<input type="hidden" name="action" value="restore"><input type="hidden" name="version_id" value="' . (int) $version->id . '">'
            . '<button class="btn btn-default btn-xs" type="submit" data-confirm="' . I18n::e('fields_restore_confirm') . '">' . I18n::e('exception_restore') . '</button></form>',
    );
}

$exchange = '<div class="scheduler-exchange"><a class="btn btn-default" href="' . Html::e($pageUrl(['func' => 'export'])) . '"><i class="rex-icon fa-download"></i> ' . I18n::e('fields_export') . '</a>'
    . '<form method="post" enctype="multipart/form-data" action="' . Html::e($pageUrl()) . '" class="scheduler-inline-form">' . $csrf->getHiddenField()
    . '<input type="hidden" name="action" value="import"><input type="file" name="schema" accept=".json,application/json" required aria-label="' . I18n::e('fields_import_file') . '"> '
    . '<button class="btn btn-default" type="submit"><i class="rex-icon fa-upload"></i> ' . I18n::e('fields_import') . '</button></form></div>'
    . '<p class="help-block">' . I18n::t('fields_exchange_help') . '</p>';

echo Html::section(I18n::e('fields_versions'), $exchange . ('' !== $rows
    ? '<table class="table table-striped"><thead><tr><th>' . I18n::e('fields_col_version') . '</th><th>' . I18n::e('fields_col_date') . '</th><th>' . I18n::e('fields_col_by') . '</th><th>' . I18n::e('fields') . '</th><th>' . I18n::e('fields_note') . '</th><th></th></tr></thead><tbody>' . $rows . '</tbody></table>'
    : ''), class: 'default');
