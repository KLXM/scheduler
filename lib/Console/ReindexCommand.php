<?php

declare(strict_types=1);

namespace KLXM\Scheduler\Console;

use KLXM\Scheduler\Scheduler;
use rex_console_command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class ReindexCommand extends rex_console_command
{
    protected function configure(): void
    {
        $this
            ->setDescription('Berechnet die Vorkommen aller Termine neu oder schiebt den Horizont der Serien weiter')
            ->addOption('extend', null, InputOption::VALUE_NONE, 'Nur Serien, deren berechneter Horizont zu kurz ist');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = $this->getStyle($input, $output);
        $result = $input->getOption('extend') ? Scheduler::indexer()->extendHorizon() : Scheduler::indexer()->reindexAll();
        $io->success(sprintf('%d Termine verarbeitet, %d Vorkommen im Index.', $result['events'], $result['occurrences']));

        return self::SUCCESS;
    }
}
