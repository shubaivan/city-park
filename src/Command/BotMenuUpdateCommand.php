<?php

namespace App\Command;

use Psr\Log\LoggerInterface;
use SergiX44\Nutgram\Nutgram;
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
    /** Order here is the order Telegram shows in the slash menu — first line is the most visible. */
    private const MENU = [
        ['rent', '🔑 Оренда квартир'],
        ['chat', '🏘 Чат мешканців'],
        ['problem', '🔧 Заявки та скарги'],
        ['start', '🏠 Головне меню'],
        ['schedule', '📅 Бронювання альтанки'],
        ['history', '📜 Історія бронювань'],
        ['photo', '📸 Завантажити фото'],
        ['info', 'ℹ️ Інструкція та FAQ'],
        ['vote', '🗳️ Голосування'],
        ['debts', '💸 Звіт боржників'],
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
            $commands[] = ['command' => $cmd, 'description' => $desc];
        }

        // Nutgram's setMyCommands json-encodes a null scope which Telegram rejects,
        // so we call the raw API endpoint instead with only the fields we need.
        //
        // The menu is registered for PRIVATE CHATS ONLY. Registered without a scope it
        // lands in Telegram's default scope, which also covers groups — so the bot's
        // commands appeared in the residents' chat autocomplete, a resident tapped
        // "/problem@che_city_park_bot" there, and nothing happened, because the global
        // middleware drops every group update by design. Better not to offer the button
        // than to answer a tap the bot must ignore.
        try {
            $ok = $this->bot->sendRequest('setMyCommands', [
                'commands' => json_encode($commands, JSON_UNESCAPED_UNICODE),
                'scope' => json_encode(['type' => 'all_private_chats']),
            ]);
        } catch (\Throwable $t) {
            $this->logger->error('setMyCommands failed: ' . $t->getMessage());
            $io->error($t->getMessage());
            return Command::FAILURE;
        }

        if (!$ok) {
            $io->warning('Telegram returned false from setMyCommands');
            return Command::FAILURE;
        }

        // Setting the private scope does not empty the default one: whatever was
        // registered there before still shows in groups. It has to be cleared explicitly.
        try {
            $this->bot->sendRequest('deleteMyCommands', []);
            $io->writeln('<info>Default scope cleared — commands no longer show in groups.</info>');
        } catch (\Throwable $t) {
            // Not fatal: the private menu is already in place, the group list is only noise.
            $this->logger->warning('deleteMyCommands (default scope) failed: ' . $t->getMessage());
            $io->warning('Could not clear the default scope: ' . $t->getMessage());
        }

        $io->success('Bot menu updated (private chats only): ' . count($commands) . ' commands');
        foreach (self::MENU as [$cmd, $desc]) {
            $io->writeln(sprintf('  /%s — %s', $cmd, $desc));
        }
        return Command::SUCCESS;
    }
}
