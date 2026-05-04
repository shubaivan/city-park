<?php

namespace App\Command;

use App\Repository\ScheduledSetRepository;
use App\Service\SchedulePavilionService;
use App\Service\UkDateFormatter;
use App\Service\WeatherService;
use Psr\Log\LoggerInterface;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'RemindCommand',
    description: 'remind user about schedule pavilion',
)]
class RemindCommand extends Command
{
    public function __construct(
        private LoggerInterface $logger,
        private ScheduledSetRepository $repository,
        private Nutgram $bot,
        private WeatherService $weatherService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $date = SchedulePavilionService::createNewDate();
            $io = new SymfonyStyle($input, $output);

            $this->log($io, sprintf('start RemindCommand at %s', $date->format('Y-m-d H:i:s')));

            $nextHour = (intval($date->format('H')) + 1) % 24;
            $currentHour = intval($date->format('H'));

            $this->log($io, sprintf(
                'year %s, month %s, day %s, currentHour %s, nextHour %s',
                $date->format('Y'),
                $date->format('m'),
                $date->format('d'),
                $currentHour,
                $nextHour
            ));

            foreach ([1, 2] as $pavilion) {
                // Remind about upcoming booking (starts in 15 min)
                $upcomingSets = $this->repository->getByParams(
                    $pavilion,
                    $date->format('Y'),
                    $date->format('m'),
                    $date->format('d'),
                    $nextHour
                );

                $this->log($io, sprintf('upcoming: count %s, pavilion %s', count($upcomingSets), $pavilion));

                foreach ($upcomingSets as $scheduledSet) {
                    $scheduledAt = $scheduledSet->getScheduledAt();
                    $text = sprintf(
                        "⏰ Ваше бронювання альтанки №%s починається о <b>%s</b>\n📅 %s\nЧекаємо на вас!",
                        $scheduledSet->getPavilion(),
                        UkDateFormatter::time($scheduledAt),
                        UkDateFormatter::dayDate($scheduledAt)
                    );
                    $forecast = $this->weatherService->getDailyForecastLine($scheduledAt);
                    if ($forecast !== null) {
                        $text .= "\nПрогноз: " . $forecast;
                    }

                    $this->bot->sendMessage(
                        text: $text,
                        chat_id: $scheduledSet->getTelegramUserid()->getChatId(),
                        parse_mode: ParseMode::HTML
                    );

                    $this->log($io, sprintf('reminded (upcoming) set id %s, pavilion %s', $scheduledSet->getId(), $pavilion));
                }

                // Remind about ending booking (ends in 15 min)
                // onlyFuture: false because the booking already started ~45 min ago,
                // so its scheduled_at is in the past relative to "now".
                $endingSets = $this->repository->getByParams(
                    $pavilion,
                    $date->format('Y'),
                    $date->format('m'),
                    $date->format('d'),
                    $currentHour,
                    null,
                    false
                );

                $this->log($io, sprintf('ending: count %s, pavilion %s', count($endingSets), $pavilion));

                foreach ($endingSets as $scheduledSet) {
                    $endHour = ($currentHour + 1) % 24;
                    $endLabel = UkDateFormatter::hourEmoji($endHour) . ' ' . sprintf('%02d:00', $endHour);
                    $this->bot->sendMessage(
                        text: sprintf(
                            '🔔 Ваше бронювання альтанки №%s закінчується о <b>%s</b>. Будь ласка, звільніть альтанку.',
                            $scheduledSet->getPavilion(),
                            $endLabel
                        ),
                        chat_id: $scheduledSet->getTelegramUserid()->getChatId(),
                        parse_mode: ParseMode::HTML
                    );

                    $this->log($io, sprintf('reminded (ending) set id %s, pavilion %s', $scheduledSet->getId(), $pavilion));
                }
            }

            $io->success('Success');
        } catch (\Throwable $t) {
            $this->logger->error($t->getMessage());
            $io->error('Error');
            $io->error($t->getMessage());
        }

        return Command::SUCCESS;
    }

    private function log(SymfonyStyle $io, string $msg): void
    {
        $this->logger->info($msg);
        $io->success($msg);
    }
}
