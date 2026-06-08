<?php

namespace App\Command;

use App\Service\SchedulePavilionService;
use App\Service\WeatherService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'WarmWeatherCommand',
    description: 'Pre-warm weather forecast cache so first user does not wait for Open-Meteo',
)]
class WarmWeatherCommand extends Command
{
    public function __construct(
        private LoggerInterface $logger,
        private WeatherService $weatherService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $today = SchedulePavilionService::createNewDate();
            $line = $this->weatherService->getDailyForecastLine($today);

            if ($line === null) {
                $io->warning('Forecast not available; cache not warmed.');
                $this->logger->warning('WarmWeatherCommand: forecast unavailable');
                return Command::SUCCESS;
            }

            // Plain writeln, NOT $io->success(): the forecast line contains emoji
            // (☀️ ⛅ …) and SymfonyStyle's success block pads its box via wcswidth(),
            // which probes '/\p{Emoji}/u'. Some prod libpcre2 builds report PCRE >= 10.40
            // but lack the Emoji property, so that preg_match throws an ERROR-level
            // warning on every successful run. writeln() does no width calculation.
            $output->writeln('<info>Weather cache warmed:</info> ' . $line);
            $this->logger->info('WarmWeatherCommand: ' . $line);
        } catch (\Throwable $t) {
            $io->error($t->getMessage());
            $this->logger->error('WarmWeatherCommand: ' . $t->getMessage());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
