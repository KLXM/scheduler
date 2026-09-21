<?php

declare(strict_types=1);

namespace KLXM\Scheduler\Import;

use KLXM\Scheduler\I18n;
use KLXM\Scheduler\Domain\Calendar;
use KLXM\Scheduler\Domain\Event;
use KLXM\Scheduler\Scheduler;
use KLXM\Scheduler\Ical\EventParser;
use KLXM\Scheduler\Service\ValidationException;

/**
 * Importiert iCalendar-Daten in einen Kalender. Termine werden über ihre UID wiedererkannt,
 * ein erneuter Import aktualisiert also statt zu duplizieren.
 */
final class IcsImporter
{
    public function __construct(
        private readonly EventParser $parser = new EventParser(),
    ) {}

    /**
     * @param string|null $source Kennung der Quelle, etwa die Abo-URL. Mit $removeMissing werden Termine
     *                            dieser Quelle gelöscht, die in den Daten nicht mehr vorkommen.
     */
    public function import(string $ics, Calendar $calendar, int $clangId, bool $dryRun = false, ?string $source = null, bool $removeMissing = false): ImportReport
    {
        $report = new ImportReport($dryRun);
        $parsedEvents = $this->parser->parse($ics);
        $importSource = null === $source ? null : 'ics:' . substr(sha1($source), 0, 16);

        try {
            Scheduler::em()->transactional(function () use ($parsedEvents, $calendar, $clangId, $report, $importSource, $removeMissing, $dryRun): void {
                $seen = [];
                foreach ($parsedEvents as $parsed) {
                    $seen[] = $parsed->uid;
                    $event = Scheduler::events()->findByUid($parsed->uid);
                    $isNew = null === $event;
                    $event ??= new Event(timezone: $calendar->timezone);

                    if (!$isNew && $event->calendarId !== $calendar->id) {
                        $report->warning($parsed->uid, I18n::t('import_uid_elsewhere'));
                        $report->count(I18n::t('count_skipped'));
                        continue;
                    }

                    $this->parser->apply($parsed, $event, $clangId, $calendar->timezone);
                    $event->calendarId = (int) $calendar->id;
                    $event->importSource = $importSource ?? $event->importSource;
                    $event->importRef = null !== $importSource ? $parsed->uid : $event->importRef;

                    try {
                        Scheduler::events()->save($event);
                        $report->count(I18n::t($isNew ? 'count_events_created' : 'count_events_updated'));
                    } catch (ValidationException $e) {
                        $report->error($parsed->uid, $e->getMessage());
                        $report->count(I18n::t('count_events_failed'));
                    }
                }

                if ($removeMissing && null !== $importSource) {
                    $stale = Scheduler::events()->query()
                        ->where('importSource', $importSource)
                        ->where('calendarId', (int) $calendar->id)
                        ->whereIn('uid', $seen, not: true)
                        ->get();
                    foreach ($stale as $event) {
                        Scheduler::events()->delete($event);
                        $report->count(I18n::t('count_events_removed'));
                    }
                }

                if ($dryRun) {
                    throw new DryRunRollback();
                }
            });
        } catch (DryRunRollback) {
            // Probelauf: alles wurde ausgeführt und wieder zurückgerollt.
        }

        return $report;
    }
}
