<?php

declare(strict_types=1);

namespace KLXM\Scheduler\Cronjob;

use KLXM\Scheduler\I18n;
use KLXM\Scheduler\Import\SubscriptionService;
use rex_cronjob;
use rex_i18n;

/**
 * Gleicht fällige Kalender-Abos ab. Der Cronjob darf häufig laufen, etwa stündlich:
 * Jedes Abo kennt sein eigenes Intervall.
 */
final class SubscriptionCronjob extends rex_cronjob
{
    public function execute(): bool
    {
        $result = new SubscriptionService()->syncDue();
        $this->setMessage(I18n::t('cron_subscriptions_result', $result['synced'], $result['failed']));

        return 0 === $result['failed'];
    }

    public function getTypeName(): string
    {
        return rex_i18n::msg('scheduler_cronjob_subscriptions');
    }
}
