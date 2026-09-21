<?php

declare(strict_types=1);

namespace KLXM\Scheduler\Console;

use KLXM\Scheduler\Scheduler;
use KLXM\Scheduler\Import\Legacy\LegacyImporter;
use rex_clang;
use rex_console_command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class ImportLegacyCommand extends rex_console_command
{
    protected function configure(): void
    {
        $this
            ->setDescription('Übernimmt Kalender, Orte und Termine aus forcal 6')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Alles ausführen, aber nichts speichern')
            ->addOption('timezone', null, InputOption::VALUE_REQUIRED, 'Zeitzone der Alttermine (Standard: Einstellung des Addons)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = $this->getStyle($input, $output);
        $clangIds = array_values(array_unique([rex_clang::getStartId(), ...rex_clang::getAllIds()]));
        $timezone = (string) ($input->getOption('timezone') ?? Scheduler::settings()->defaultTimezone());
        if (!in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
            $io->error(sprintf('Unbekannte Zeitzone "%s".', $timezone));

            return self::FAILURE;
        }

        $importer = new LegacyImporter(Scheduler::em()->connection, $clangIds, $timezone, \rex_path::addonData('forcal', 'definitions'));
        if (!$importer->isAvailable()) {
            $io->error('Es wurden keine Tabellen von forcal 6 gefunden.');

            return self::FAILURE;
        }

        $counts = $importer->sourceCounts();
        $io->title('Import aus forcal 6');
        $io->writeln(sprintf('Quelle: %d Termine, %d Kategorien, %d Orte. Zeitzone: %s', $counts['entries'], $counts['categories'], $counts['venues'], $timezone));

        $report = $importer->run((bool) $input->getOption('dry-run'));
        ReportRenderer::render($io, $report, $output->isVerbose());

        return $report->hasErrors ? self::FAILURE : self::SUCCESS;
    }
}
