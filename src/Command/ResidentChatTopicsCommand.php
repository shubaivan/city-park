<?php

namespace App\Command;

use App\Service\ResidentChatService;
use SergiX44\Nutgram\Nutgram;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Create the forum topics the bot posts into, and print their ids.
 *
 * The group was switched to Topics by hand on 07.09.2026 — that toggle belongs to the
 * owner, not to a migration. What the bot needs afterwards is the `message_thread_id` of
 * each branch, and Telegram offers no way to list topics: `createForumTopic` returns the
 * id once, at creation, and never again. So this command creates them and prints the two
 * lines to paste into `.env.local`; a topic made by hand in the app has to have its id
 * read out of a message link instead (`t.me/c/<chat>/<topic>/<message>`).
 *
 * Idempotent it is not: run it twice and the group gets two «🔧 Заявки». Run it once,
 * paste, deploy.
 */
#[AsCommand(
    name: 'resident-chat:topics',
    description: 'Create the residents\' group forum topics and print their ids',
)]
class ResidentChatTopicsCommand extends Command
{
    /** Icon colours Telegram accepts; picked to match what each branch is about. */
    private const TOPICS = [
        ResidentChatService::TOPIC_COMPLAINTS => ['🔧 Заявки', 0xFB6F5F],
        ResidentChatService::TOPIC_DEBT => ['💸 Борги', 0xF8AB00],
        ResidentChatService::TOPIC_RENTALS => ['🔑 Оренда та продаж', 0x6FB9F0],
        // The bot writes nothing here — it exists so that everything the bot *does* write
        // has somewhere else to be. Without it the chatter lands in «Заявки» and buries
        // the one answer somebody was looking for.
        self::TOPIC_SMALLTALK => ['💬 Спілкування', 0x8EEE98],
    ];

    /** Not in ResidentChatService: nothing is ever posted into it by the bot. */
    private const TOPIC_SMALLTALK = 'smalltalk';

    private const ENV_KEYS = [
        ResidentChatService::TOPIC_COMPLAINTS => 'RESIDENT_CHAT_TOPIC_COMPLAINTS',
        ResidentChatService::TOPIC_DEBT => 'RESIDENT_CHAT_TOPIC_DEBT',
        ResidentChatService::TOPIC_RENTALS => 'RESIDENT_CHAT_TOPIC_RENTALS',
    ];

    public function __construct(
        private Nutgram $bot,
        private ResidentChatService $residentChat,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be created and stop');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $chatId = $this->residentChat->chatId();

        if ($chatId === '') {
            $io->error('RESIDENT_CHAT_ID is empty — nothing to create topics in.');

            return Command::FAILURE;
        }

        $lines = [];

        foreach (self::TOPICS as $kind => [$name, $color]) {
            $already = isset(self::ENV_KEYS[$kind]) ? $this->residentChat->topic($kind) : null;

            if ($already !== null) {
                $io->writeln(sprintf('  %s — вже налаштовано (%d), пропускаю', $name, $already));
                continue;
            }

            if ($input->getOption('dry-run')) {
                $io->writeln(sprintf('  [DRY] створив би «%s»', $name));
                continue;
            }

            try {
                $topic = $this->bot->createForumTopic($chatId, $name, $color);
            } catch (\Throwable $e) {
                $io->error(sprintf(
                    'Не вдалося створити «%s»: %s. Перевірте, що бот — адміністратор із правом «Керувати темами».',
                    $name,
                    $e->getMessage(),
                ));

                return Command::FAILURE;
            }

            if ($topic === null) {
                $io->error(sprintf('Telegram не повернув тему для «%s».', $name));

                return Command::FAILURE;
            }

            $io->success(sprintf('%s → message_thread_id %d', $name, $topic->message_thread_id));

            if (isset(self::ENV_KEYS[$kind])) {
                $lines[] = sprintf('%s=%d', self::ENV_KEYS[$kind], $topic->message_thread_id);
            }
        }

        if ($lines === []) {
            $io->writeln('Нових тем не створено.');

            return Command::SUCCESS;
        }

        $io->section('Впишіть у .env.local і задеплойте:');
        $io->writeln($lines);
        $io->newLine();
        $io->note('Поки ці рядки порожні, бот пише в «General» — це не помилка, а стара поведінка.');

        return Command::SUCCESS;
    }
}
