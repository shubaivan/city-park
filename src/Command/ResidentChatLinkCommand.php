<?php

namespace App\Command;

use App\Service\ResidentChatService;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Mint the residents' chat invite link — the one and only door.
 *
 * Run once per chat. The link asks Telegram to hold every newcomer as a join request
 * instead of letting them in, which is what makes it safe to publish anywhere: the bot
 * checks the user_id that actually knocks, so a forwarded link buys an outsider nothing.
 *
 * Telegram gives no way to look a link up again, so the printed value goes straight into
 * RESIDENT_CHAT_INVITE_LINK. Running this twice makes a *second* valid door rather than
 * replacing the first — harmless, but only the one in the env is handed to residents.
 */
#[AsCommand(
    name: 'resident-chat:link',
    description: 'Create the join-request invite link for the residents\' group.',
)]
class ResidentChatLinkCommand extends Command
{
    public function __construct(
        private Nutgram $bot,
        private ResidentChatService $residentChat,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument(
            'chat_id',
            InputArgument::OPTIONAL,
            'Supergroup id (defaults to RESIDENT_CHAT_ID)',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $chatId = (string)($input->getArgument('chat_id') ?: $this->residentChat->chatId());

        if ($chatId === '') {
            $io->error('No chat id: pass one as an argument or set RESIDENT_CHAT_ID.');

            return Command::FAILURE;
        }

        try {
            $chat = $this->bot->getChat($chatId);
        } catch (\Throwable $t) {
            $io->error('Cannot read the chat: ' . $t->getMessage());

            return Command::FAILURE;
        }

        $type = $chat?->type instanceof ChatType ? $chat->type->value : (string)$chat?->type;

        // A basic group cannot hold join requests, and its id changes the moment Telegram
        // converts it — writing that id anywhere is how you end up with a dead config.
        if ($type !== ChatType::SUPERGROUP->value) {
            $io->error(sprintf(
                'Chat %s is a "%s", not a supergroup. Enable an admin feature in Telegram to '
                . 'convert it (the id changes when that happens), then re-run.',
                $chatId,
                $type,
            ));

            return Command::FAILURE;
        }

        try {
            $link = $this->bot->createChatInviteLink(
                chat_id: $chatId,
                name: 'Мешканці (через бота)',
                creates_join_request: true,
            );
        } catch (\Throwable $t) {
            $io->error('createChatInviteLink failed: ' . $t->getMessage());

            return Command::FAILURE;
        }

        if (!$link?->invite_link) {
            $io->error('Telegram returned no link — is the bot an admin with "invite users via link"?');

            return Command::FAILURE;
        }

        $io->success('Link created for ' . ($chat->title ?? $chatId));
        $io->writeln('Put these in .env.local:');
        $io->writeln('');
        $io->writeln('RESIDENT_CHAT_ID=' . $chatId);
        $io->writeln('RESIDENT_CHAT_INVITE_LINK=' . $link->invite_link);

        return Command::SUCCESS;
    }
}
