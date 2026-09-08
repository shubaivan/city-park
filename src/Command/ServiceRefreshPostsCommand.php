<?php

namespace App\Command;

use App\Entity\ServiceOffer;
use App\Repository\ServiceOfferRepository;
use App\Service\DeepLink;
use App\Service\ResidentChatService;
use App\Service\ServiceOfferService;
use Psr\Log\LoggerInterface;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Message\ParseMode;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Rewrite the text of service posts that are older than the text.
 *
 * A post in the chat is written once and then edited in place — never deleted and
 * re-posted, because Telegram refuses to delete a message older than 48 hours and an offer
 * lives 30 days. That is right, and it has one consequence: a post only catches up with a
 * change in the wording if its offer happens to be republished afterwards.
 *
 * It caught somebody out on the board's first day. «Електрик» was posted at 12:36 and last
 * republished at 12:49; the phone was added to the chat post at 12:53. The two adverts that
 * followed carried a number and that one did not, and no amount of waiting would have fixed
 * it — the offer was finished, so nothing was going to touch its post again.
 *
 * **This command can only edit, never publish.** An offer with no `chat_message_id` is
 * skipped and reported rather than announced: a repair tool that can put a *new* advert in
 * front of 457 people is a repair tool nobody can safely run at 23:40, which is exactly
 * when the need for one shows up. «message is not modified» is counted as already-correct,
 * not as a failure — most posts are, and Telegram says so by refusing the edit.
 */
#[AsCommand(
    name: 'service:refresh-posts',
    description: 'Re-render the residents-chat posts of active service offers (edit only, never post).',
)]
class ServiceRefreshPostsCommand extends Command
{
    public function __construct(
        private Nutgram $bot,
        private ServiceOfferService $offers,
        private ServiceOfferRepository $repository,
        private ResidentChatService $residentChat,
        private DeepLink $links,
        private LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    /** Between edits — Telegram rate-limits them per chat, and this is never in a hurry. */
    private const PAUSE_MS = 400;

    protected function configure(): void
    {
        $this->addOption('id', null, InputOption::VALUE_REQUIRED, 'Refresh one offer by id');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print the text that would be written and stop');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->residentChat->isConfigured()) {
            $io->error('RESIDENT_CHAT_ID не налаштовано — редагувати нічого.');

            return Command::FAILURE;
        }

        $chatId = (int)$this->residentChat->chatId();
        $dry = (bool)$input->getOption('dry-run');
        $only = $input->getOption('id');

        $offers = $only !== null
            ? array_filter([$this->repository->find((int)$only)])
            : $this->repository->findBy(['status' => ServiceOffer::STATUS_ACTIVE], ['id' => 'ASC']);

        if ($offers === []) {
            $io->warning('Оголошень не знайдено.');

            return Command::SUCCESS;
        }

        $edited = $already = $skipped = $failed = 0;

        foreach ($offers as $offer) {
            $label = sprintf('#%d %s', $offer->getId(), $offer->getTitle());
            $messageId = $offer->getChatMessageId();

            if ($messageId === null) {
                $io->writeln(sprintf('  <comment>%s — посту в чаті немає, пропускаю</comment>', $label));
                $skipped++;

                continue;
            }

            if ($dry) {
                $io->writeln(sprintf('  [DRY] %s → повідомлення %d', $label, $messageId));
                $io->writeln('  ' . str_replace("\n", "\n  ", $this->offers->chatPost($offer)));
                $io->newLine();

                continue;
            }

            try {
                $this->bot->editMessageText(
                    text: $this->offers->chatPost($offer),
                    chat_id: $chatId,
                    message_id: $messageId,
                    parse_mode: ParseMode::HTML,
                    reply_markup: $this->links->button(DeepLink::KIND_SERVICE, $offer->getId()),
                );

                $io->writeln(sprintf('  <info>%s — оновлено</info>', $label));
                $edited++;
            } catch (\Throwable $e) {
                if (str_contains($e->getMessage(), 'not modified')) {
                    $io->writeln(sprintf('  %s — уже актуальний', $label));
                    $already++;

                    continue;
                }

                $io->writeln(sprintf('  <error>%s — %s</error>', $label, $e->getMessage()));
                $this->logger->warning('service post refresh failed', [
                    'offer_id' => $offer->getId(),
                    'message_id' => $messageId,
                    'error' => $e->getMessage(),
                ]);
                $failed++;
            }

            usleep(self::PAUSE_MS * 1000);
        }

        if ($dry) {
            $io->success('Пробний запуск — нічого не змінено.');

            return Command::SUCCESS;
        }

        $io->success(sprintf(
            'Оновлено: %d, уже актуальних: %d, без посту: %d, помилок: %d.',
            $edited,
            $already,
            $skipped,
            $failed,
        ));

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
