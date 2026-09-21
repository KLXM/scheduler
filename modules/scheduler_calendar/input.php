<?php

use KLXM\Scheduler\Scheduler;

$selected = array_map('strval', (array) rex_var::toArray('REX_VALUE[1]'));
?>
<fieldset class="form-horizontal">
    <div class="form-group">
        <label class="col-sm-3 control-label" for="scheduler-cal-calendars"><?= \KLXM\Scheduler\I18n::e('module_calendars') ?></label>
        <div class="col-sm-9">
            <select class="form-control" id="scheduler-cal-calendars" name="REX_INPUT_VALUE[1][]" multiple size="5">
                <?php foreach (Scheduler::calendars()->all() as $calendar): ?>
                    <option value="<?= (int) $calendar->id ?>"<?= in_array((string) $calendar->id, $selected, true) ? ' selected' : '' ?>><?= rex_escape($calendar->name) ?></option>
                <?php endforeach; ?>
            </select>
            <p class="help-block"><?= \KLXM\Scheduler\I18n::e('module_calendars_help') ?></p>
        </div>
    </div>
    <div class="form-group">
        <label class="col-sm-3 control-label" for="scheduler-cal-view"><?= \KLXM\Scheduler\I18n::e('module_initial_view') ?></label>
        <div class="col-sm-9">
            <select class="form-control" id="scheduler-cal-view" name="REX_INPUT_VALUE[2]">
                <?php foreach (['dayGridMonth' => \KLXM\Scheduler\I18n::t('view_month'), 'listMonth' => \KLXM\Scheduler\I18n::t('view_list'), 'timeGridWeek' => \KLXM\Scheduler\I18n::t('view_week'), 'multiMonthYear' => \KLXM\Scheduler\I18n::t('view_year')] as $value => $label): ?>
                    <option value="<?= $value ?>"<?= $value === 'REX_VALUE[2]' ? ' selected' : '' ?>><?= $label ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <div class="form-group">
        <label class="col-sm-3 control-label"><?= \KLXM\Scheduler\I18n::e('module_detail_page') ?></label>
        <div class="col-sm-9">REX_LINK[id=1 widget=1]<p class="help-block"><?= \KLXM\Scheduler\I18n::e('module_detail_page_help') ?></p></div>
    </div>
</fieldset>
