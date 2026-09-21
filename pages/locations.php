<?php

declare(strict_types=1);

use KLXM\Scheduler\Backend\CustomFields;
use KLXM\Scheduler\Backend\Flash;
use KLXM\Scheduler\Backend\Geo;
use KLXM\Scheduler\Backend\Html;
use KLXM\Scheduler\Domain\Enum\SchemaTarget;
use KLXM\Scheduler\Domain\Location;
use KLXM\Scheduler\I18n;
use KLXM\Scheduler\Scheduler;

$func = rex_request('func', 'string');
$id = rex_request('id', 'int');
$csrf = rex_csrf_token::factory('scheduler_locations');
$repository = Scheduler::locations();
$listUrl = rex_url::backendPage('scheduler/locations', [], false);
$eventTable = Scheduler::em()->connection->table('scheduler_event');

echo Flash::render();

if ('delete' === $func) {
    $location = $repository->find($id);
    if (!$csrf->isValid()) {
        Flash::error(rex_i18n::msg('csrf_token_invalid'));
    } elseif (null !== $location) {
        // Termine behalten die Adresse als freien Text, damit nichts verloren geht.
        Scheduler::em()->connection->execute('UPDATE ' . $eventTable . ' SET `location_text` = ?, `location_id` = NULL WHERE `location_id` = ?', [$location->label, $location->id]);
        $repository->delete($location);
        Flash::success(I18n::t('location_deleted'));
    }
    rex_response::sendRedirect($listUrl);
}

if ('add' === $func || 'edit' === $func) {
    $location = 'edit' === $func ? $repository->find($id) : new Location();
    if (null === $location) {
        echo rex_view::error(I18n::e('location_not_found'));

        return;
    }
    $errors = [];

    if ('post' === rex_request_method()) {
        $text = static fn (string $name): ?string => '' === trim(rex_post($name, 'string')) ? null : trim(rex_post($name, 'string'));
        $location->name = trim(rex_post('name', 'string'));
        $location->street = $text('street');
        $location->zip = $text('zip');
        $location->city = $text('city');
        $location->country = $text('country');
        $location->url = $text('url');
        [$location->latitude, $location->longitude, $geoError] = Geo::parse(rex_post('coordinates', 'string'));
        $location->active = rex_post('active', 'bool');
        [$location->custom, $errors] = CustomFields::process(SchemaTarget::Location, $location->custom);

        if (!$csrf->isValid()) {
            $errors['csrf'] = rex_i18n::msg('csrf_token_invalid');
        }
        if ('' === $location->name) {
            $errors['name'] = I18n::t('location_name_required');
        }
        if (null !== $location->url && false === filter_var($location->url, FILTER_VALIDATE_URL)) {
            $errors['url'] = I18n::t('invalid_url');
        }
        if (null !== $geoError) {
            $errors['geo'] = $geoError;
        }

        if ([] === $errors) {
            $repository->save($location);
            Flash::success(I18n::t('location_saved'));
            rex_response::sendRedirect(rex_post('save_and_close', 'bool') ? $listUrl : rex_url::backendPage('scheduler/locations', ['func' => 'edit', 'id' => $location->id], false));
        }
        echo rex_view::error(Html::e(implode(' ', array_unique($errors))));
    }

    $body = Html::field(I18n::t('name'), Html::input('text', 'name', $location->name, ['id' => 'scheduler-name', 'required' => true, 'maxlength' => 191]), 'scheduler-name', error: $errors['name'] ?? null, required: true)
        . Html::field(I18n::t('location_street'), Html::input('text', 'street', $location->street, ['id' => 'scheduler-street', 'autocomplete' => 'off']), 'scheduler-street')
        . Html::field(I18n::t('location_zip_city'), '<div class="scheduler-period-row">' . Html::input('text', 'zip', $location->zip, ['aria-label' => I18n::t('location_zip'), 'maxlength' => 20, 'size' => 8]) . Html::input('text', 'city', $location->city, ['aria-label' => I18n::t('location_city'), 'id' => 'scheduler-city']) . '</div>', 'scheduler-city')
        . Html::field(I18n::t('location_country'), Html::input('text', 'country', $location->country, ['id' => 'scheduler-country', 'maxlength' => 100]), 'scheduler-country')
        . Html::field(I18n::t('location_coordinates'), Geo::input($location->latitude, $location->longitude), 'scheduler-coordinates', I18n::t(Geo::hasMapPicker() ? 'location_coordinates_help_map' : 'location_coordinates_help_plain'), $errors['geo'] ?? null)
        . Html::field(I18n::t('location_website'), Html::input('url', 'url', $location->url, ['id' => 'scheduler-url', 'placeholder' => 'https://']), 'scheduler-url', error: $errors['url'] ?? null)
        . Html::field(I18n::t('status'), Html::toggle('active', $location->active, I18n::t('calendar_active_label'), 'scheduler-active'), 'scheduler-active', I18n::t('location_active_help'));

    echo '<form action="' . Html::e(rex_url::backendPage('scheduler/locations', array_filter(['func' => $func, 'id' => $location->id]), false)) . '" method="post">' . $csrf->getHiddenField()
        . Html::section(I18n::e(null === $location->id ? 'location_add' : 'location_edit'), $body)
        . CustomFields::render(SchemaTarget::Location, $location->custom, $errors)
        . Html::formActions($listUrl) . '</form>';

    return;
}

$counts = [];
foreach (Scheduler::em()->connection->fetchAll('SELECT `location_id`, COUNT(*) AS c FROM ' . $eventTable . ' WHERE `location_id` IS NOT NULL GROUP BY `location_id`') as $row) {
    $counts[(int) $row['location_id']] = (int) $row['c'];
}

$rows = '';
foreach ($repository->query()->orderBy('name')->get() as $location) {
    $editUrl = rex_url::backendPage('scheduler/locations', ['func' => 'edit', 'id' => $location->id], false);
    $rows .= '<tr><td class="rex-table-icon"><i class="rex-icon fa-location-dot"></i></td>'
        . '<td data-title="' . I18n::e('name') . '"><a href="' . Html::e($editUrl) . '">' . Html::e($location->name) . '</a></td>'
        . '<td data-title="' . I18n::e('location_address') . '">' . Html::e(implode(', ', array_filter([$location->street, trim($location->zip . ' ' . $location->city)]))) . '</td>'
        . '<td data-title="' . I18n::e('events') . '">' . ($counts[(int) $location->id] ?? 0) . '</td>'
        . '<td data-title="' . I18n::e('status') . '">' . Html::activeState($location->active) . '</td>'
        . '<td class="rex-table-action"><a href="' . Html::e($editUrl) . '"><i class="rex-icon rex-icon-edit"></i> ' . I18n::e('action_edit') . '</a></td>'
        . '<td class="rex-table-action"><a href="' . Html::e(rex_url::backendPage('scheduler/locations', ['func' => 'delete', 'id' => $location->id] + $csrf->getUrlParams(), false)) . '" data-confirm="' . I18n::e('location_delete_confirm') . '"><i class="rex-icon rex-icon-delete"></i> ' . I18n::e('action_delete') . '</a></td></tr>';
}

echo Html::section(I18n::e('locations'), '<table class="table table-striped table-hover"><thead><tr>'
    . '<th class="rex-table-icon"><a href="' . Html::e(rex_url::backendPage('scheduler/locations', ['func' => 'add'], false)) . '" title="' . I18n::e('location_add') . '"><i class="rex-icon rex-icon-add"></i></a></th>'
    . '<th>' . I18n::e('name') . '</th><th>' . I18n::e('location_address') . '</th><th>' . I18n::e('events') . '</th><th>' . I18n::e('status') . '</th><th class="rex-table-action" colspan="2">' . I18n::e('functions') . '</th></tr></thead><tbody>'
    . ('' !== $rows ? $rows : '<tr><td colspan="7" class="scheduler-empty">' . I18n::e('location_none') . '</td></tr>') . '</tbody></table>', class: 'default');
