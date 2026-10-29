<?php

namespace App\Command;

use App\Repository\TelegramUserRepository;
use Psr\Log\LoggerInterface;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * One announcement, in a DM, to everybody who ever pressed /start.
 *
 * For news about the bot itself — «з'явився розділ 🚗 Мої авто» — which concerns every
 * reader and has no chat post that reaches the 263 people the bot knows but the residents'
 * chat does not. Never for anything aimed at one household (debts go through their own
 * commands), and never from a cron: it is run by hand, once, after the text was read.
 *
 * - **`--only=<telegram id>` first.** The text is HTML and goes to five hundred people;
 *   the one place to see it rendered is a phone, before anybody else does.
 * - **A journal makes a re-run safe.** Every delivered chat id is appended to
 *   `var/broadcast/<name>.sent`, and a second run skips them — so an interrupted run is
 *   finished by running it again, never by sending the first half twice.
 * - **Throttled** under Telegram's ~30 messages a second, and a user who blocked the bot is
 *   a counted failure, not a stop.
 */
#[AsCommand(
    name: 'bot:broadcast',
    description: 'Send one HTML announcement to every bot user (journaled, re-run safe)',
)]
class BotBroadcastCommand extends Command
{
    private const PAUSE_US = 60_000;

    public function __construct(
        private Nutgram $bot,
        private TelegramUserRepository $users,
        private LoggerInterface $logger,
        private string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'Journal name, e.g. cars-2026-10-20')
            ->addArgument('file', InputArgument::REQUIRED, 'HTML text of the message')
            ->addOption('button', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Inline button as «label|callback_data» or «label|https://…», one row each, repeatable')
            ->addOption('only', null, InputOption::VALUE_REQUIRED, 'Send to this telegram id only (a test)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Count who would get it and stop');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $name = (string)$input->getArgument('name');

        if (preg_match('/^[a-z0-9][a-z0-9\-]{2,60}$/', $name) !== 1) {
            $io->error('name: lower-case letters, digits and dashes only');

            return Command::FAILURE;
        }

        $text = trim((string)@file_get_contents((string)$input->getArgument('file')));

        if ($text === '') {
            $io->error('the message file is empty or unreadable');

            return Command::FAILURE;
        }

        $markup = null;

        foreach ((array)$input->getOption('button') as $spec) {
            [$label, $target] = array_pad(explode('|', (string)$spec, 2), 2, '');
            $markup ??= InlineKeyboardMarkup::make();
            // A link to a topic of the residents' chat opens only for its members, so it
            // rides next to a callback that lets everybody else in through the bot.
            $markup->addRow(str_starts_with($target, 'https://')
                ? InlineKeyboardButton::make($label, url: $target)
                : InlineKeyboardButton::make($label, callback_data: $target));
        }

        $only = $input->getOption('only');
        $journal = $this->projectDir . '/var/broadcast/' . $name . '.sent';
        @mkdir(dirname($journal), 0775, true);
        $sent = $only === null && is_file($journal)
            ? array_flip(array_filter(array_map('trim', (array)file($journal))))
            : [];

        $targets = [];

        foreach ($this->users->findAll() as $user) {
            $chatId = (string)$user->getChatId();

            // A group id here is the 02.09.2026 corruption, never a person.
            if ($chatId === '' || str_starts_with($chatId, '-') || isset($sent[$chatId])) {
                continue;
            }

            if ($only !== null && (string)$user->getTelegramId() !== (string)$only) {
                continue;
            }

            $targets[$chatId] = true;
        }

        $io->writeln(sprintf('Отримають: %d (уже надіслано раніше: %d)', count($targets), count($sent)));

        if ($input->getOption('dry-run')) {
            return Command::SUCCESS;
        }

        $ok = 0;
        $failed = 0;

        foreach (array_keys($targets) as $chatId) {
            try {
                $this->bot->sendMessage(
                    text: $text,
                    chat_id: $chatId,
                    parse_mode: ParseMode::HTML,
                    disable_web_page_preview: true,
                    reply_markup: $markup,
                );
                $ok++;

                if ($only === null) {
                    file_put_contents($journal, $chatId . "\n", FILE_APPEND);
                }
            } catch (\Throwable $e) {
                $failed++;
                $this->logger->warning('broadcast not delivered', [
                    'name' => $name,
                    'chat_id' => $chatId,
                    'error' => $e->getMessage(),
                ]);
            }

            usleep(self::PAUSE_US);
        }

        $io->success(sprintf('Надіслано: %d, не доставлено: %d (заблокували бота тощо)', $ok, $failed));

        return Command::SUCCESS;
    }
}
