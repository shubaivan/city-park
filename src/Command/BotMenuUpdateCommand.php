<?php

namespace App\Command;

use Psr\Log\LoggerInterface;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Command\BotCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'bot:menu:update',
    description: 'Push the slash-command menu to Telegram (setMyCommands).',
)]
class BotMenuUpdateCommand extends Command
{
    /** [command, description] pairs. Keep <= 32 chars per description (Telegram limit). */
    private const MENU = [
        ['start', 'Головне меню'],
        ['schedule', '📅 Бронювання альтанки'],
        ['history', '📜 Історія бронювань'],
        ['photo', '📸 Завантажити фото'],
        ['info', 'ℹ️ Інструкція та FAQ'],
    ];

    public function __construct(
        private Nutgram $bot,
        private LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $commands = [];
        foreach (self::MENU as [$cmd, $desc]) {
            $commands[] = BotCommand::make(command: $cmd, description: $desc);
        }

        try {
            $ok = $this->bot->setMyCommands($commands);
        } catch (\Throwable $t) {
            $this->logger->error('setMyCommands failed: ' . $t->getMessage());
            $io->error($t->getMessage());
            return Command::FAILURE;
        }

        if (!$ok) {
            $io->warning('Telegram returned false from setMyCommands');
            return Command::FAILURE;
        }

        $io->success('Bot menu updated: ' . count($commands) . ' commands');
        foreach (self::MENU as [$cmd, $desc]) {
            $io->writeln(sprintf('  /%s — %s', $cmd, $desc));
        }
        return Command::SUCCESS;
    }
}
