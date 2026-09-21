<?php

declare(strict_types=1);

namespace KLXM\Scheduler\Console;

use KLXM\Scheduler\Scheduler;
use KLXM\Scheduler\Service\ValidationException;
use rex_console_command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class SchemaSyncCommand extends rex_console_command
{
    protected function configure(): void
    {
        $this
            ->setDescription('Spielt exportierte Custom-Field-Schemata ein, etwa beim Deployment')
            ->addArgument('files', InputArgument::IS_ARRAY | InputArgument::REQUIRED, 'Eine oder mehrere exportierte JSON-Dateien');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = $this->getStyle($input, $output);
        $failed = false;

        foreach ((array) $input->getArgument('files') as $file) {
            if (!is_readable($file)) {
                $io->error(sprintf('Datei "%s" ist nicht lesbar.', $file));
                $failed = true;
                continue;
            }
            try {
                $changed = Scheduler::schemas()->importJson((string) file_get_contents($file), 'Deployment: ' . basename($file));
                $io->writeln(sprintf('%s: %s', basename($file), $changed ? 'neue Version veröffentlicht' : 'unverändert'));
            } catch (ValidationException|\JsonException $e) {
                $io->error(basename($file) . ': ' . $e->getMessage());
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
