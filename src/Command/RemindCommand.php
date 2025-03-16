<?php

namespace App\Command;

use App\Repository\ScheduledSetRepository;
use App\Service\SchedulePavilionService;
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

    public function __construct(
        ScheduledSetRepository $repository,
        Nutgram $bot,
    )
    {
        parent::__construct();
        $this->repository = $repository;
        $this->bot = $bot;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $date = SchedulePavilionService::createNewDate();
        $nextHour = (intval($date->format('H')) + 1) % 24;

        $scheduledSets = $this->repository->getByParams(
            1,
            $date->format('Y'),
            $date->format('m'),
            $date->format('d'),
            $nextHour
        );

        foreach ($scheduledSets as $scheduledSet) {
            /** @var Message $message */
            $message = $this->bot->sendMessage(
                text: sprintf('У вас є бронювання альтанки №%s яке почнеться о %s', $scheduledSet->getPavilion(), $scheduledSet->getScheduledAt()->format('Y-m-d H-i-s')),
                chat_id: $scheduledSet->getTelegramUserid()->getChatId(),
                parse_mode: ParseMode::HTML
            );
        }

        $scheduledSets = $this->repository->getByParams(
            2,
            $date->format('Y'),
            $date->format('m'),
            $date->format('d'),
            $nextHour
        );

        foreach ($scheduledSets as $scheduledSet) {
            /** @var Message $message */
            $message = $this->bot->sendMessage(
                text: sprintf('У вас є бронювання альтанки №%s яке почнеться о %s', $scheduledSet->getPavilion(), $scheduledSet->getScheduledAt()->format('Y-m-d H-i-s')),
                chat_id: $scheduledSet->getTelegramUserid()->getChatId(),
                parse_mode: ParseMode::HTML
            );
        }

        $io->success('You have a new command! Now make it your own! Pass --help to see your options.');

        return Command::SUCCESS;
    }
}
