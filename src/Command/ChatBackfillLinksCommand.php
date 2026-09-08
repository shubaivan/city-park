<?php

namespace App\Command;

use App\Entity\Complaint;
use App\Entity\DebtSnapshot;
use App\Entity\RentalListing;
use App\Entity\ServiceOffer;
use App\Repository\ComplaintRepository;
use App\Repository\DebtSnapshotRepository;
use App\Repository\RentalListingRepository;
use App\Repository\ServiceOfferRepository;
use App\Service\DeepLink;
use App\Service\ResidentChatService;
use Psr\Log\LoggerInterface;
use SergiX44\Nutgram\Nutgram;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Hang «↗️ Відкрити в боті» under the posts that were published before the button existed.
 *
 * Every board learned to link back on 08.09.2026, and every post older than that afternoon
 * still ends in «деталі — у боті» pointing at nothing in particular. They are the posts
 * people actually scroll past, so leaving them is leaving the dead end in place for
 * everything already published.
 *
 * **Only the keyboard is touched**, never the text. `editMessageReplyMarkup` cannot get a
 * caption wrong, cannot lose a status line somebody edited by hand, and — unlike
 * `deleteMessage` — has no 48-hour limit, which is the whole reason a backfill is possible
 * at all. A post whose wording still says «у боті, «🔧 Заявки»» reads fine with a button
 * underneath; it is belt and braces, not a contradiction.
 *
 * Silent for the group: editing a message notifies nobody, so this can run over a hundred
 * posts without ringing a single phone.
 *
 * Idempotent — re-attaching the same markup is either a no-op Telegram reports as «message
 * is not modified» (counted as already-done, not as a failure) or the same button again.
 */
#[AsCommand(
    name: 'chat:backfill-links',
    description: 'Attach the «Відкрити в боті» button to posts published before it existed.',
)]
class ChatBackfillLinksCommand extends Command
{
    public function __construct(
        private Nutgram $bot,
        private DeepLink $links,
        private ResidentChatService $residentChat,
        private ServiceOfferRepository $offers,
        private RentalListingRepository $listings,
        private ComplaintRepository $complaints,
        private DebtSnapshotRepository $snapshots,
        private LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'List what would be edited and stop');
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

        $done = $already = $failed = 0;

        foreach ($this->targets() as [$kind, $id, $messageId, $label]) {
            $markup = $this->links->button($kind, $id);

            if ($markup === null) {
                $io->writeln(sprintf('  <comment>%s — не вдалося побудувати посилання, пропускаю</comment>', $label));
                $failed++;

                continue;
            }

            if ($dry) {
                $io->writeln(sprintf('  [DRY] %s → повідомлення %d', $label, $messageId));

                continue;
            }

            try {
                $this->bot->editMessageReplyMarkup(
                    chat_id: $chatId,
                    message_id: $messageId,
                    reply_markup: $markup,
                );
                $io->writeln(sprintf('  ✅ %s', $label));
                $done++;
            } catch (\Throwable $e) {
                // «message is not modified» means the button is already there — that is a
                // success on a re-run, not a failure worth reporting as one.
                if (str_contains($e->getMessage(), 'not modified')) {
                    $io->writeln(sprintf('  · %s — кнопка вже є', $label));
                    $already++;

                    continue;
                }

                $io->writeln(sprintf('  <error>✗ %s — %s</error>', $label, $e->getMessage()));
                $this->logger->warning('backfill: could not attach the link', [
                    'kind' => $kind,
                    'id' => $id,
                    'message_id' => $messageId,
                    'error' => $e->getMessage(),
                ]);
                $failed++;
            }
        }

        if ($dry) {
            $io->success('Це був dry-run — нічого не змінено.');

            return Command::SUCCESS;
        }

        $io->success(sprintf('Готово. Додано: %d, вже було: %d, не вдалося: %d', $done, $already, $failed));

        return Command::SUCCESS;
    }

    /**
     * Everything with a post still standing in the chat.
     *
     * Live adverts only — a withdrawn one has had its post deleted or struck through, and
     * putting a working button under «⛔ Оголошення знято» would be worse than leaving it.
     * Complaints are taken whatever their status: a fixed lift is exactly the post somebody
     * wants to open, to see what was done.
     *
     * @return iterable<array{0: string, 1: int, 2: int, 3: string}>
     */
    private function targets(): iterable
    {
        $now = new \DateTime();

        foreach ($this->offers->findActive($now, 500) as $offer) {
            if ($offer instanceof ServiceOffer && $offer->getChatMessageId() !== null) {
                yield [DeepLink::KIND_SERVICE, (int)$offer->getId(), $offer->getChatMessageId(), '🛠 ' . $offer->getTitle()];
            }
        }

        foreach ($this->listings->findActive($now, 500) as $listing) {
            if ($listing instanceof RentalListing && $listing->getChatMessageId() !== null) {
                yield [DeepLink::KIND_RENTAL, (int)$listing->getId(), $listing->getChatMessageId(), '🔑 ' . $listing->getAccount()->getUnitLabel()];
            }
        }

        foreach ($this->complaints->findAllNewestFirst() as $complaint) {
            if ($complaint instanceof Complaint && $complaint->getChatMessageId() !== null) {
                yield [DeepLink::KIND_COMPLAINT, (int)$complaint->getId(), $complaint->getChatMessageId(), '🔧 Заявка №' . $complaint->getId()];
            }
        }

        // The debt post is pinned, so it is the one a resident sees without scrolling.
        $snapshot = $this->snapshots->lastAnnounced();

        if ($snapshot instanceof DebtSnapshot && $snapshot->getAnnouncedMessageId() !== null) {
            yield [DeepLink::KIND_DEBT, (int)$snapshot->getId(), $snapshot->getAnnouncedMessageId(), '💸 Борги'];
        }
    }
}
