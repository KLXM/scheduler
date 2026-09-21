<?php

declare(strict_types=1);

namespace KLXM\Scheduler\Console;

use KLXM\Scheduler\Import\ImportReport;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * @internal
 */
final class ReportRenderer
{
    public static function render(SymfonyStyle $io, ImportReport $report, bool $verbose): void
    {
        if ([] !== $report->counts) {
            $io->table(['Ergebnis', 'Anzahl'], array_map(null, array_keys($report->counts), array_values($report->counts)));
        }

        foreach ($report->messages as $message) {
            if ('info' === $message['level'] && !$verbose) {
                continue;
            }
            $line = $message['subject'] . ': ' . $message['message'];
            match ($message['level']) {
                'error' => $io->writeln('<error>FEHLER</error>  ' . $line),
                'warning' => $io->writeln('<comment>HINWEIS</comment> ' . $line),
                default => $io->writeln('<info>INFO</info>    ' . $line),
            };
        }

        if ($report->dryRun) {
            $io->note('Probelauf: Es wurde nichts gespeichert.');
        }
    }
}
