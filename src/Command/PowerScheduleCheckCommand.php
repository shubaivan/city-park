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
 * Read Черкасиобленерго's channel and bring «💡 Світло» up to date. Cron, every two
 * minutes: the company re-posts during the day and a change that reaches the house half
 * an hour late has already turned somebody's lift off with them in it.
 *
 * Doing nothing is the common outcome and prints nothing; `--dry-run` writes the pictures
 * to var/ and prints the captions instead of sending.
 */
#[AsCommand(name: 'power:schedule:check', description: 'Post the outage schedule for our buildings into the «Світло» topic')]
class PowerScheduleCheckCommand extends Command
{
    public function __construct(private PowerScheduleAnnouncer $announcer)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Render to var/ and print; send nothing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $done = $this->announcer->run((bool)$input->getOption('dry-run'));
        } catch (\Throwable $e) {
            $io->error('power:schedule:check — ' . $e->getMessage());

            return Command::FAILURE;
        }

        foreach ($done as $line) {
            $io->writeln($line);
        }

        return Command::SUCCESS;
    }
}
