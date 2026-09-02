<?php

namespace App\Command;

use SergiX44\Nutgram\Nutgram;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Re-register the webhook with the update types the bot actually needs.
 *
 * Telegram sends a bot only the update types listed in `allowed_updates`, and the
 * default list leaves out `chat_join_request` — which is the entire residents' chat
 * gate. Miss this and nothing breaks loudly: people simply queue at the door forever
 * while the bot never hears them knock. Run it once after deploying the gate.
 *
 * The URL is read back from Telegram, so this cannot accidentally re-point the webhook
 * somewhere else; pass one explicitly only when moving hosts.
 */
#[AsCommand(
    name: 'bot:webhook:update',
    description: 'Re-set the Telegram webhook so join-request updates are delivered.',
)]
class BotWebhookUpdateCommand extends Command
{
    /**
     * What we handle, and nothing else — every extra type is another update hitting
     * /hook for no reason.
     */
    private const ALLOWED_UPDATES = [
        'message',
        'callback_query',
        'chat_join_request',
        'my_chat_member',
    ];

    public function __construct(private Nutgram $bot)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument(
            'url',
            InputArgument::OPTIONAL,
            'Webhook URL (defaults to the one Telegram already has)',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $url = (string)$input->getArgument('url');

        if ($url === '') {
            $info = $this->bot->getWebhookInfo();
            $url = (string)($info?->url ?? '');

            if ($url === '') {
                $io->error('Telegram has no webhook set — pass the URL as an argument.');

                return Command::FAILURE;
            }
        }

        try {
            $ok = $this->bot->setWebhook($url, allowed_updates: self::ALLOWED_UPDATES);
        } catch (\Throwable $t) {
            $io->error('setWebhook failed: ' . $t->getMessage());

            return Command::FAILURE;
        }

        if (!$ok) {
            $io->error('Telegram returned false from setWebhook');

            return Command::FAILURE;
        }

        $io->success('Webhook set: ' . $url);
        $io->listing(self::ALLOWED_UPDATES);

        return Command::SUCCESS;
    }
}
