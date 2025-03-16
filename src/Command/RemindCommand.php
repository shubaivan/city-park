<?php

namespace App\Command;

use App\Repository\ScheduledSetRepository;
use App\Service\SchedulePavilionService;
use Psr\Log\LoggerInterface;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use SergiX44\Nutgram\Telegram\Types\Message\Message;
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
    private ScheduledSetRepository $repository;
    private Nutgram $bot;
    private LoggerInterface $logger;

    public function __construct(
        LoggerInterface $logger,
        ScheduledSetRepository $repository,
        Nutgram $bot,
    )
    {
        parent::__construct();
        $this->repository = $repository;
        $this->bot = $bot;
        $this->logger = $logger;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $date = SchedulePavilionService::createNewDate();
            $io = new SymfonyStyle($input, $output);

            $msg = sprintf('start RemindCommand at %s', $date->format('Y-m-d H:i:s'));
            $this->logger->info($msg);
            $io->success($msg);


            $nextHour = (intval($date->format('H')) + 1) % 24;

            $msg = sprintf(
                'year %s, month %s, day %s, next hour %s',
                $date->format('Y'),
                $date->format('m'),
                $date->format('d'),
                $nextHour
            );
            $this->logger->info($msg);
            $io->success($msg);


            $scheduledSets = $this->repository->getByParams(
                1,
                $date->format('Y'),
                $date->format('m'),
                $date->format('d'),
                $nextHour
            );

            $msg = sprintf(
                'count %s pavilion %s',
                count($scheduledSets),
                1,
            );
            $this->logger->info($msg);
            $io->success($msg);

            foreach ($scheduledSets as $scheduledSet) {
                /** @var Message $message */
                $message = $this->bot->sendMessage(
                    text: sprintf('У вас є бронювання альтанки №%s яке почнеться о %s', $scheduledSet->getPavilion(), $scheduledSet->getScheduledAt()->format('Y-m-d H-i-s')),
                    chat_id: $scheduledSet->getTelegramUserid()->getChatId(),
                    parse_mode: ParseMode::HTML
                );

                $msg = sprintf(
                    'scheduledSet id %s pavilion %s was reminded',
                    $scheduledSet->getId(),
                    1,
                );
                $this->logger->info($msg);
                $io->success($msg);
            }

            $scheduledSets = $this->repository->getByParams(
                2,
                $date->format('Y'),
                $date->format('m'),
                $date->format('d'),
                $nextHour
            );

            $msg = sprintf(
                'count %s pavilion %s',
                count($scheduledSets),
                2,
            );
            $this->logger->info($msg);
            $io->success($msg);

            foreach ($scheduledSets as $scheduledSet) {
                /** @var Message $message */
                $message = $this->bot->sendMessage(
                    text: sprintf('У вас є бронювання альтанки №%s яке почнеться о %s', $scheduledSet->getPavilion(), $scheduledSet->getScheduledAt()->format('Y-m-d H-i-s')),
                    chat_id: $scheduledSet->getTelegramUserid()->getChatId(),
                    parse_mode: ParseMode::HTML
                );

                $msg = sprintf(
                    'scheduledSet id %s pavilion %s was reminded',
                    $scheduledSet->getId(),
                    1,
                );
                $this->logger->info($msg);
                $io->success($msg);
            }
            $io->success('Success');
        } catch (\Throwable $t) {
            $this->logger->error($t->getMessage());
            $io->error('Error');
            $io->error($t->getMessage());
        }

        return Command::SUCCESS;
    }
}
