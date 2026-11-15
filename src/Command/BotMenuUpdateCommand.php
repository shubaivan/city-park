<?php

namespace App\Command;

use App\Repository\TelegramUserRepository;
use App\Service\GuardService;
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
    /**
     * Order here is the order Telegram shows in the slash menu — first line is the most visible.
     *
     * `/start` leads it. Every other entry opens one section; this is the one that opens
     * everything, and it is the way back when somebody is lost inside a card — which is
     * exactly when they are scrolling this list. Fifth in a list of thirteen identical rows
     * it was simply not being found (Иван, 10.09.2026), and a resident who cannot get back
     * to the menu reads that as the bot having stopped working. 🔑 Оренда keeps the top of
     * the inline menu, where there is no «головне меню» to compete with it.
     *
     * `/phone` is second (23.10.2026): it is the one entry that opens all the others, and a
     * newcomer told «натисніть /phone» looks for it here first — Helen G did, found
     * nothing, and asked the Viber group for a screenshot. It answers a confirmed resident
     * with «✅ номер підтверджено», so the line costs them nothing.
     */
    private const MENU = [
        ['start', '🏠 Головне меню'],
        ['phone', '📱 Мій телефон / підтвердити'],
        ['rent', '🔑 Оренда та продаж'],
        ['services', '🛠 Послуги'],
        ['chat', '🏘 Чат мешканців'],
        ['problem', '🔧 Заявки та скарги'],
        ['schedule', '📅 Бронювання альтанки'],
        ['history', '📜 Історія бронювань'],
        ['photo', '📸 Завантажити фото'],
        ['qr', '🪪 Мій QR-код'],
        ['pass', '👷 Пропуск для робітників'],
        ['info', 'ℹ️ Інструкція та FAQ'],
        ['vote', '🗳️ Голосування'],
        ['debts', '💸 Звіт боржників'],
    ];

    /**
     * The post's phone sees these two and nothing else (Иван, 15.11.2026). Pushed to each
     * guard's own chat (scope `chat`), which Telegram prefers over `all_private_chats`.
     * A guard who also lives here is linked to a flat and keeps the residents' list — his
     * chat scope is deleted instead, so he falls back to MENU.
     */
    private const GUARD_MENU = [
        ['start', '🏠 Головне меню'],
        ['panel', '🔐 Вхід у панель'],
    ];

    public function __construct(
        private Nutgram $bot,
        private LoggerInterface $logger,
        private GuardService $guards,
        private TelegramUserRepository $users,
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

        $this->pushGuardMenus($io);

        return Command::SUCCESS;
    }

    /** @return array<int, array{command: string, description: string}> */
    public static function guardCommands(): array
    {
        return array_map(
            static fn (array $row): array => ['command' => $row[0], 'description' => $row[1]],
            self::GUARD_MENU,
        );
    }

    /**
     * Not fatal per chat: a guard id that never opened the bot answers «chat not found»,
     * and the residents' menu is already in place.
     */
    private function pushGuardMenus(SymfonyStyle $io): void
    {
        foreach ($this->guards->guardTelegramIds() as $telegramId) {
            $scope = json_encode(['type' => 'chat', 'chat_id' => (int)$telegramId]);
            $livesHere = $this->users->getByTelegramId($telegramId)?->getAccount() !== null;

            try {
                if ($livesHere) {
                    $this->bot->sendRequest('deleteMyCommands', ['scope' => $scope]);
                    $io->writeln(sprintf('<info>Guard %s is linked to a flat — keeps the residents\' menu.</info>', $telegramId));

                    continue;
                }

                $this->bot->sendRequest('setMyCommands', [
                    'commands' => json_encode(self::guardCommands(), JSON_UNESCAPED_UNICODE),
                    'scope' => $scope,
                ]);
                $io->writeln(sprintf('<info>Guard %s: /start, /panel only.</info>', $telegramId));
            } catch (\Throwable $t) {
                $this->logger->warning(sprintf('guard menu for %s failed: %s', $telegramId, $t->getMessage()));
                $io->warning(sprintf('Guard %s: %s', $telegramId, $t->getMessage()));
            }
        }
    }
}
