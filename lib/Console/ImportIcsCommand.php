<?php

declare(strict_types=1);

namespace KLXM\Scheduler\Console;

use KLXM\Scheduler\Scheduler;
use KLXM\Scheduler\Ical\InvalidIcalException;
use KLXM\Scheduler\Import\FetchException;
use KLXM\Scheduler\Import\IcsFetcher;
use KLXM\Scheduler\Import\IcsImporter;
use rex_clang;
use rex_console_command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class ImportIcsCommand extends rex_console_command
{
    protected function configure(): void
    {
        $this
            ->setDescription('Importiert eine ICS-Datei oder -URL in einen Kalender')
            ->addArgument('source', InputArgument::REQUIRED, 'Pfad oder URL der ICS-Daten')
            ->addArgument('calendar', InputArgument::REQUIRED, 'ID oder Slug des Zielkalenders')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Alles ausführen, aber nichts speichern')
            ->addOption('sync', null, InputOption::VALUE_NONE, 'Termine dieser Quelle löschen, die nicht mehr enthalten sind')
            ->addOption('clang', null, InputOption::VALUE_REQUIRED, 'Sprach-ID für Titel und Beschreibung');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = $this->getStyle($input, $output);
        $source = (string) $input->getArgument('source');
        $key = (string) $input->getArgument('calendar');

        $calendar = ctype_digit($key) ? Scheduler::calendars()->find((int) $key) : Scheduler::calendars()->findBySlug($key);
        if (null === $calendar) {
            $io->error(sprintf('Kalender "%s" nicht gefunden.', $key));

            return self::FAILURE;
        }

        try {
            $ics = 1 === preg_match('#^(https?|webcals?)://#i', $source)
                ? new IcsFetcher()->fetch($source)
                : (is_readable($source) ? (string) file_get_contents($source) : '');
        } catch (FetchException $e) {
            $io->error($e->getMessage());

            return self::FAILURE;
        }
        if ('' === $ics) {
            $io->error('Die Datei ist leer oder nicht lesbar.');

            return self::FAILURE;
        }

        try {
            $report = new IcsImporter()->import(
                $ics,
                $calendar,
                (int) ($input->getOption('clang') ?? rex_clang::getStartId()),
                (bool) $input->getOption('dry-run'),
                $source,
                (bool) $input->getOption('sync'),
            );
        } catch (InvalidIcalException $e) {
            $io->error($e->getMessage());

            return self::FAILURE;
        }

        ReportRenderer::render($io, $report, $output->isVerbose());

        return $report->hasErrors ? self::FAILURE : self::SUCCESS;
    }
}
