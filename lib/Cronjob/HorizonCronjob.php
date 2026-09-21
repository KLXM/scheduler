<?php

declare(strict_types=1);

namespace KLXM\Scheduler\Cronjob;

use KLXM\Scheduler\I18n;
use KLXM\Scheduler\Scheduler;
use rex_cronjob;
use rex_i18n;

/**
 * Schiebt den Planungshorizont endloser und langer Serien rollierend weiter.
 * Einmal täglich genügt.
 */
final class HorizonCronjob extends rex_cronjob
{
    public function execute(): bool
    {
        $result = Scheduler::indexer()->extendHorizon();
        $this->setMessage(I18n::t('cron_horizon_result', $result['events'], $result['occurrences']));

        return true;
    }

    public function getTypeName(): string
    {
        return rex_i18n::msg('scheduler_cronjob');
    }
}
