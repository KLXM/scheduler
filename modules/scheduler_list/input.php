<?php

use KLXM\Scheduler\Scheduler;

$selected = array_map('strval', (array) rex_var::toArray('REX_VALUE[1]'));
?>
<fieldset class="form-horizontal">
    <div class="form-group">
        <label class="col-sm-3 control-label" for="scheduler-list-headline"><?= \KLXM\Scheduler\I18n::e('module_headline') ?></label>
        <div class="col-sm-9"><input class="form-control" id="scheduler-list-headline" type="text" name="REX_INPUT_VALUE[4]" value="REX_VALUE[4]"></div>
    </div>
    <div class="form-group">
        <label class="col-sm-3 control-label" for="scheduler-list-calendars"><?= \KLXM\Scheduler\I18n::e('module_calendars') ?></label>
        <div class="col-sm-9">
            <select class="form-control" id="scheduler-list-calendars" name="REX_INPUT_VALUE[1][]" multiple size="5">
                <?php foreach (Scheduler::calendars()->all() as $calendar): ?>
                    <option value="<?= (int) $calendar->id ?>"<?= in_array((string) $calendar->id, $selected, true) ? ' selected' : '' ?>><?= rex_escape($calendar->name) ?></option>
                <?php endforeach; ?>
            </select>
            <p class="help-block"><?= \KLXM\Scheduler\I18n::e('module_calendars_help') ?></p>
        </div>
    </div>
    <div class="form-group">
        <label class="col-sm-3 control-label" for="scheduler-list-limit"><?= \KLXM\Scheduler\I18n::e('module_limit') ?></label>
        <div class="col-sm-9"><input class="form-control" id="scheduler-list-limit" type="number" min="1" max="200" name="REX_INPUT_VALUE[2]" value="<?= 'REX_VALUE[2]' ?: 10 ?>"></div>
    </div>
    <div class="form-group">
        <label class="col-sm-3 control-label" for="scheduler-list-months"><?= \KLXM\Scheduler\I18n::e('module_period') ?></label>
        <div class="col-sm-9">
            <select class="form-control" id="scheduler-list-months" name="REX_INPUT_VALUE[3]">
                <?php foreach ([0 => \KLXM\Scheduler\I18n::t('module_period_all'), 1 => \KLXM\Scheduler\I18n::t('module_period_month'), 3 => \KLXM\Scheduler\I18n::t('module_period_months', 3), 6 => \KLXM\Scheduler\I18n::t('module_period_months', 6), 12 => \KLXM\Scheduler\I18n::t('module_period_months', 12)] as $value => $label): ?>
                    <option value="<?= $value ?>"<?= (string) $value === 'REX_VALUE[3]' ? ' selected' : '' ?>><?= $label ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <div class="form-group">
        <label class="col-sm-3 control-label" for="scheduler-list-tag"><?= \KLXM\Scheduler\I18n::e('module_tag') ?></label>
        <div class="col-sm-9"><input class="form-control" id="scheduler-list-tag" type="text" name="REX_INPUT_VALUE[7]" value="REX_VALUE[7]"></div>
    </div>
    <div class="form-group">
        <label class="col-sm-3 control-label"><?= \KLXM\Scheduler\I18n::e('module_detail_page') ?></label>
        <div class="col-sm-9">REX_LINK[id=1 widget=1]<p class="help-block"><?= \KLXM\Scheduler\I18n::e('module_detail_page_help') ?> <?= \KLXM\Scheduler\I18n::e('module_detail_page_optional') ?></p></div>
    </div>
    <div class="form-group">
        <div class="col-sm-offset-3 col-sm-9">
            <label><input type="hidden" name="REX_INPUT_VALUE[5]" value="0"><input type="checkbox" name="REX_INPUT_VALUE[5]" value="1"<?= '1' === 'REX_VALUE[5]' ? ' checked' : '' ?>> <?= \KLXM\Scheduler\I18n::e('module_group_months') ?></label><br>
            <label><input type="hidden" name="REX_INPUT_VALUE[6]" value="0"><input type="checkbox" name="REX_INPUT_VALUE[6]" value="1"<?= '0' === 'REX_VALUE[6]' ? '' : ' checked' ?>> <?= \KLXM\Scheduler\I18n::e('module_show_teaser') ?></label><br>
            <label><input type="hidden" name="REX_INPUT_VALUE[8]" value="0"><input type="checkbox" name="REX_INPUT_VALUE[8]" value="1"<?= '0' === 'REX_VALUE[8]' ? '' : ' checked' ?>> <?= \KLXM\Scheduler\I18n::e('module_include_css') ?></label>
        </div>
    </div>
</fieldset>
