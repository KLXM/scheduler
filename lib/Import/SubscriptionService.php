<?php

declare(strict_types=1);

namespace KLXM\Scheduler\Import;

use KLXM\Scheduler\I18n;
use DateTimeImmutable;
use DateTimeZone;
use KLXM\Scheduler\Domain\Subscription;
use KLXM\Scheduler\Ical\InvalidIcalException;
use KLXM\Scheduler\Orm\Repository;
use KLXM\Scheduler\Scheduler;

/**
 * Gleicht abonnierte ICS-Adressen ab. Ein Fehler bei einem Abo hält die übrigen nicht auf;
 * Status und Meldung des letzten Laufs stehen am Abo.
 */
final class SubscriptionService
{
    public function __construct(
        private readonly IcsFetcher $fetcher = new IcsFetcher(),
        private readonly IcsImporter $importer = new IcsImporter(),
    ) {}

    /**
     * @return Repository<Subscription>
     */
    public function repository(): Repository
    {
        return Scheduler::em()->repository(Subscription::class);
    }

    /**
     * @return list<Subscription>
     */
    public function all(): array
    {
        return $this->repository()->query()->orderBy('id')->get();
    }

    /**
     * @return ImportReport|null null, wenn der Abgleich scheiterte; die Meldung steht dann am Abo
     */
    public function sync(Subscription $subscription): ?ImportReport
    {
        $report = null;
        try {
            $calendar = Scheduler::calendars()->find($subscription->calendarId)
                ?? throw new FetchException(I18n::t('sub_target_gone'));
            $ics = $this->fetcher->fetch($subscription->url);
            $report = $this->importer->import($ics, $calendar, $subscription->clangId, false, $subscription->url, $subscription->removeMissing);

            $subscription->lastStatus = $report->hasErrors ? 'error' : 'ok';
            $subscription->lastMessage = implode(', ', array_map(static fn (string $label, int $count): string => $count . ' ' . $label, array_keys($report->counts), $report->counts)) ?: I18n::t('sub_no_changes');
        } catch (FetchException|InvalidIcalException $e) {
            $subscription->lastStatus = 'error';
            $subscription->lastMessage = $e->getMessage();
        }

        $subscription->lastSyncAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->repository()->save($subscription);

        return $report;
    }

    /**
     * Gleicht alle fälligen Abos ab. Gedacht für den Cronjob.
     *
     * @return array{synced: int, failed: int}
     */
    public function syncDue(bool $force = false): array
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $result = ['synced' => 0, 'failed' => 0];
        foreach ($this->all() as $subscription) {
            if (!$subscription->active || (!$force && !$subscription->isDue($now))) {
                continue;
            }
            $this->sync($subscription);
            ++$result['ok' === $subscription->lastStatus ? 'synced' : 'failed'];
        }

        return $result;
    }
}
