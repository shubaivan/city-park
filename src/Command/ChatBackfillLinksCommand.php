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

    /** Between edits. Telegram tolerates roughly this rate on one chat. */
    private const PAUSE_MS = 400;

    /** How long to wait when Telegram asks, before giving up on a message. */
    private const MAX_BACKOFF_SECONDS = 60;

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

            $outcome = $this->attach($io, $chatId, $messageId, $markup, $label, $kind, $id);

            match ($outcome) {
                'done' => $done++,
                'already' => $already++,
                default => $failed++,
            };

            // Telegram rate-limits edits to one chat, and a backfill is by definition a
            // burst of them: the first real run tripped «Too Many Requests: retry after 30»
            // after eight. A pause between messages is cheaper than the retry it avoids,
            // and this command is never in a hurry.
            usleep(self::PAUSE_MS * 1000);
        }

        if ($dry) {
            $io->success('Це був dry-run — нічого не змінено.');

            return Command::SUCCESS;
        }

        $io->success(sprintf('Готово. Додано: %d, вже було: %d, не вдалося: %d', $done, $already, $failed));

        return Command::SUCCESS;
    }

    /**
     * One edit, with the one retry Telegram actually asks for.
     *
     * A 429 carries «retry after N», and honouring it is the difference between a backfill
     * that finishes and one that has to be re-run until it happens to fit. Anything else —
     * a timeout, a deleted message — is left to the operator: the command is idempotent, so
     * running it again is the cheapest possible recovery.
     *
     * @return 'done'|'already'|'failed'
     */
    private function attach(
        SymfonyStyle $io,
        int $chatId,
        int $messageId,
        \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup $markup,
        string $label,
        string $kind,
        int $id,
    ): string {
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $this->bot->editMessageReplyMarkup(
                    chat_id: $chatId,
                    message_id: $messageId,
                    reply_markup: $markup,
                );
                $io->writeln(sprintf('  ✅ %s', $label));

                return 'done';
            } catch (\Throwable $e) {
                $message = $e->getMessage();

                // «message is not modified» means the button is already there — a success
                // on a re-run, not a failure worth reporting as one.
                if (str_contains($message, 'not modified')) {
                    $io->writeln(sprintf('  · %s — кнопка вже є', $label));

                    return 'already';
                }

                $wait = $this->retryAfter($message);

                if ($wait !== null && $attempt === 0) {
                    $io->writeln(sprintf('  ⏳ %s — Telegram просить зачекати %dс', $label, $wait));
                    sleep($wait);

                    continue;
                }

                $io->writeln(sprintf('  <error>✗ %s — %s</error>', $label, $message));
                $this->logger->warning('backfill: could not attach the link', [
                    'kind' => $kind,
                    'id' => $id,
                    'message_id' => $messageId,
                    'error' => $message,
                ]);

                return 'failed';
            }
        }

        return 'failed';
    }

    /** Seconds out of «Too Many Requests: retry after 30», clamped so a bad number cannot hang the run. */
    private function retryAfter(string $message): ?int
    {
        if (preg_match('/retry after (\d+)/i', $message, $m) !== 1) {
            return null;
        }

        return min((int)$m[1] + 1, self::MAX_BACKOFF_SECONDS);
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
