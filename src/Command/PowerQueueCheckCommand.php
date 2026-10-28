<?php

namespace App\Command;

use App\Service\PowerScheduleAnnouncer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Ask Черкасиобленерго which hourly-outage queue each building is in. Cron, daily.
 *
 * The schedule post names queues, not addresses; this is what ties «3.1» to Козацька 19,
 * and what notices the day the company moves a building to another queue — in which case
 * the topic is told and the next schedule is drawn for the new one.
 */
#[AsCommand(name: 'power:queue:check', description: 'Re-check which outage queue each building is in')]
class PowerQueueCheckCommand extends Command
{
    public function __construct(private PowerScheduleAnnouncer $announcer)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Ask and print; store and announce nothing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $report = $this->announcer->refreshQueues((bool)$input->getOption('dry-run'));
        } catch (\Throwable $e) {
            $io->error('power:queue:check — ' . $e->getMessage());

            return Command::FAILURE;
        }

        $rows = [];
        foreach ($report as $house => ['was' => $was, 'now' => $now]) {
            $rows[] = ['буд. ' . $house, $was ?? '—', $now ?? 'обленерго не відповіло'];
        }

        $io->table(['Будинок', 'Було', 'Зараз'], $rows);

        return Command::SUCCESS;
    }
}
