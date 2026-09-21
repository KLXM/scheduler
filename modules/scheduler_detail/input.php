<fieldset class="form-horizontal">
    <div class="form-group">
        <label class="col-sm-3 control-label"><?= \KLXM\Scheduler\I18n::e('module_overview_page') ?></label>
        <div class="col-sm-9">REX_LINK[id=1 widget=1]<p class="help-block"><?= \KLXM\Scheduler\I18n::e('module_overview_page_help') ?></p></div>
    </div>
    <div class="form-group">
        <label class="col-sm-3 control-label" for="scheduler-detail-upcoming"><?= \KLXM\Scheduler\I18n::e('module_upcoming') ?></label>
        <div class="col-sm-9"><input class="form-control" id="scheduler-detail-upcoming" type="number" min="0" max="50" name="REX_INPUT_VALUE[1]" value="<?= 'REX_VALUE[1]' !== '' ? 'REX_VALUE[1]' : 5 ?>"></div>
    </div>
</fieldset>
<p class="help-block"><?= \KLXM\Scheduler\I18n::t('module_detail_help') ?></p>
