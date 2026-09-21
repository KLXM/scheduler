<?php

declare(strict_types=1);

use KLXM\Scheduler\Backend\Flash;
use KLXM\Scheduler\Backend\Html;
use KLXM\Scheduler\Frontend\ModuleInstaller;
use KLXM\Scheduler\I18n;

$csrf = rex_csrf_token::factory('scheduler_modules');
$installer = new ModuleInstaller(rex_path::addon('scheduler', 'modules'));

if ('post' === rex_request_method()) {
    if (!$csrf->isValid()) {
        echo rex_view::error(rex_i18n::msg('csrf_token_invalid'));
    } else {
        try {
            $installer->install(rex_post('module', 'string'));
            Flash::success(I18n::t('modules_installed'));
        } catch (InvalidArgumentException $e) {
            Flash::error($e->getMessage());
        }
        rex_response::sendRedirect(rex_url::currentBackendPage([], false));
    }
}

echo Flash::render();

$rows = '';
foreach ($installer->modules() as $key => $module) {
    $state = match (true) {
        null === $module['installedId'] => '<span class="text-muted">' . I18n::e('modules_state_missing') . '</span>',
        $module['outdated'] => '<span class="text-warning">' . I18n::e('modules_state_changed') . '</span>',
        default => '<span class="rex-online">' . I18n::e('modules_state_installed') . '</span>',
    };
    $label = I18n::e(null === $module['installedId'] ? 'modules_install' : 'modules_reset');
    $rows .= '<tr><td><strong>' . Html::e($module['name']) . '</strong><br><small class="text-muted">' . Html::e($module['description']) . '</small></td>'
        . '<td>' . $state . (null !== $module['installedId'] ? ' <a href="' . Html::e(rex_url::backendPage('modules/modules', ['function' => 'edit', 'module_id' => $module['installedId']], false)) . '">(#' . $module['installedId'] . ')</a>' : '') . '</td>'
        . '<td class="rex-table-action"><form method="post" action="' . Html::e(rex_url::currentBackendPage([], false)) . '" class="scheduler-inline-form">' . $csrf->getHiddenField()
        . '<input type="hidden" name="module" value="' . Html::e($key) . '"><button class="btn btn-' . (null === $module['installedId'] ? 'save' : 'default') . ' btn-xs" type="submit"'
        . ($module['outdated'] ? ' data-confirm="' . I18n::e('modules_reset_confirm') . '"' : '') . '>' . $label . '</button></form></td></tr>';
}

echo Html::section(
    I18n::e('modules_title'),
    '<p>' . I18n::t('modules_intro') . '</p>'
    . '<table class="table table-striped"><thead><tr><th>' . I18n::e('modules_module') . '</th><th>' . I18n::e('status') . '</th><th></th></tr></thead><tbody>' . $rows . '</tbody></table>'
    . '<p class="help-block">' . I18n::e('modules_order') . '</p>',
    class: 'default',
);
