<?php

namespace App\Command;

use App\Service\ResidentChatService;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Sticker\Sticker;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Give a forum topic its icon.
 *
 * A topic created without one shows the first letter of its name on a coloured circle —
 * «З», «О», «С», «Б» down the sidebar, which is exactly as readable as it sounds. The icon
 * cannot be any emoji: Telegram keeps a fixed set for this, returned by
 * `getForumTopicIconStickers`, and each is addressed by a custom emoji id rather than by
 * the character. So the command takes the emoji you want, looks it up in that set, and
 * says what is available when it is not there.
 *
 * Run without arguments it prints the whole set — which is the only way to find out what
 * this bot is allowed to use.
 */
#[AsCommand(
    name: 'resident-chat:topic-icon',
    description: 'Set a forum topic\'s icon (or list the icons Telegram allows)',
)]
class ResidentChatTopicIconCommand extends Command
{
    public function __construct(
        private Nutgram $bot,
        private ResidentChatService $residentChat,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('thread_id', InputArgument::OPTIONAL, 'message_thread_id of the topic')
            ->addArgument('emoji', InputArgument::OPTIONAL, 'Which icon to set, e.g. 🔧');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->residentChat->isConfigured()) {
            $io->error('RESIDENT_CHAT_ID is empty.');

            return Command::FAILURE;
        }

        /** @var Sticker[] $stickers */
        $stickers = $this->bot->getForumTopicIconStickers() ?? [];

        if ($stickers === []) {
            $io->error('Telegram не повернув жодної іконки.');

            return Command::FAILURE;
        }

        $byEmoji = [];

        foreach ($stickers as $sticker) {
            $emoji = (string)$sticker->emoji;

            if ($emoji !== '' && !isset($byEmoji[$emoji])) {
                $byEmoji[$emoji] = (string)$sticker->custom_emoji_id;
            }
        }

        $threadId = $input->getArgument('thread_id');
        $emoji = (string)$input->getArgument('emoji');

        if ($threadId === null || $emoji === '') {
            $io->section(sprintf('Доступні іконки (%d)', count($byEmoji)));
            $io->writeln(implode(' ', array_keys($byEmoji)));
            $io->newLine();
            $io->note('Виклик: resident-chat:topic-icon <message_thread_id> <емодзі>');

            return Command::SUCCESS;
        }

        if (!isset($byEmoji[$emoji])) {
            $io->error(sprintf('Іконки «%s» немає в наборі Telegram. Доступні: %s', $emoji, implode(' ', array_keys($byEmoji))));

            return Command::FAILURE;
        }

        try {
            $this->bot->editForumTopic(
                (int)$this->residentChat->chatId(),
                (int)$threadId,
                icon_custom_emoji_id: $byEmoji[$emoji],
            );
        } catch (\Throwable $e) {
            $io->error('Telegram відмовив: ' . $e->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf('Тема %s тепер має іконку %s', $threadId, $emoji));

        return Command::SUCCESS;
    }
}
