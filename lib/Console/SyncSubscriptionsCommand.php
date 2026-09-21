<?php

declare(strict_types=1);

namespace KLXM\Scheduler\Console;

use KLXM\Scheduler\Import\SubscriptionService;
use rex_console_command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class SyncSubscriptionsCommand extends rex_console_command
{
    protected function configure(): void
    {
        $this
            ->setDescription('Gleicht die abonnierten ICS-Kalender ab')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Auch Abos, deren Intervall noch nicht abgelaufen ist');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = $this->getStyle($input, $output);
        $service = new SubscriptionService();
        $result = $service->syncDue((bool) $input->getOption('all'));

        foreach ($service->all() as $subscription) {
            $io->writeln(sprintf('%s  %s  %s', 'error' === $subscription->lastStatus ? '<error>FEHLER</error>' : '<info>ok</info>    ', $subscription->url, (string) $subscription->lastMessage));
        }
        $io->writeln(sprintf('%d abgeglichen, %d mit Fehlern.', $result['synced'], $result['failed']));

        return 0 === $result['failed'] ? self::SUCCESS : self::FAILURE;
    }
}
