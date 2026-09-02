<?php

namespace App\Service;

use App\Entity\DebtSnapshot;
use App\Repository\AccountRepository;
use App\Repository\DebtSnapshotRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;

/**
 * Records what the house owed at each import, and tells the residents' chat about it.
 *
 * Called at the end of both import paths (debt:import-file and /admin/debt/upload). The
 * snapshot is always taken; the announcement is what has guards on it:
 *
 * - **Once a day.** The accountant re-uploading a corrected file must not put a second
 *   list of debtors in front of the whole house twenty minutes after the first.
 * - **Only when the chat exists.** Same `isConfigured()` gate as the menu button: the
 *   group is created by hand in Telegram, not by a migration.
 * - **Never fatal.** A failed post must not roll back an import that already updated 143
 *   accounts and messaged the residents it blocked or unblocked.
 */
class DebtAnnouncer
{
    public function __construct(
        private AccountRepository $accountRepository,
        private DebtSnapshotRepository $snapshots,
        private DebtBoardService $board,
        private ResidentChatService $residentChat,
        private EntityManagerInterface $em,
        private Nutgram $bot,
        private LoggerInterface $logger,
    ) {}

    /**
     * @return DebtSnapshot the snapshot just recorded
     */
    public function recordSnapshot(): DebtSnapshot
    {
        $totals = $this->accountRepository->debtTotals();

        $snapshot = (new DebtSnapshot())
            ->setTotal($totals['total'])
            ->setDebtors($totals['debtors']);

        $this->em->persist($snapshot);
        $this->em->flush();

        return $snapshot;
    }

    /**
     * @return string one of: sent | skipped:not-configured | skipped:already-today | failed
     */
    public function announce(DebtSnapshot $snapshot, bool $force = false): string
    {
        if (!$this->residentChat->isConfigured()) {
            return 'skipped:not-configured';
        }

        if (!$force && $this->snapshots->announcedSince(new \DateTimeImmutable('today 00:00'))) {
            $this->logger->info('debt announcement skipped: already posted today', [
                'snapshot_id' => $snapshot->getId(),
            ]);

            return 'skipped:already-today';
        }

        $text = $this->board->chatAnnouncement($snapshot, $this->snapshots->previousTo($snapshot));

        $chatId = (int)$this->residentChat->chatId();

        try {
            $message = $this->bot->sendMessage(
                text: $text,
                chat_id: $chatId,
                parse_mode: ParseMode::HTML,
            );
        } catch (\Throwable $e) {
            $this->logger->error('debt announcement failed', [
                'snapshot_id' => $snapshot->getId(),
                'error' => $e->getMessage(),
            ]);

            return 'failed';
        }

        $messageId = $message?->message_id;

        $snapshot->markAnnounced($messageId);
        $this->em->flush();

        if ($messageId !== null) {
            $this->repin($chatId, $messageId, $snapshot);
        }

        $this->logger->info('debt announcement posted', [
            'snapshot_id' => $snapshot->getId(),
            'total' => $snapshot->getTotal(),
            'debtors' => $snapshot->getDebtors(),
        ]);

        return 'sent';
    }

    /**
     * Pin the new summary and unpin last month's.
     *
     * The post is the one thing in the chat worth keeping at the top — it is published
     * monthly and read all month. Pinned without a notification, because the message
     * itself has just notified everyone and a second ping for the pin is noise.
     *
     * The new one is pinned before the old one is removed, so the chat is never left
     * without a current summary at the top. Nothing here may throw: the announcement has
     * already been published, and a pin that fails is a cosmetic problem, not a reason to
     * report the post as failed.
     */
    private function repin(int $chatId, int $messageId, DebtSnapshot $snapshot): void
    {
        try {
            $this->bot->pinChatMessage($chatId, $messageId, disable_notification: true);
        } catch (\Throwable $e) {
            $this->logger->warning('debt announcement pin failed', ['error' => $e->getMessage()]);

            return;
        }

        $previous = $this->snapshots->lastAnnounced(except: $snapshot);

        if (!$previous instanceof DebtSnapshot || $previous->getAnnouncedMessageId() === null) {
            return;
        }

        try {
            $this->bot->unpinChatMessage($chatId, $previous->getAnnouncedMessageId());
        } catch (\Throwable $e) {
            // Already unpinned by a resident, or the message was deleted — either way the
            // new summary is pinned, which is all that matters.
            $this->logger->info('debt announcement unpin of previous failed', [
                'message_id' => $previous->getAnnouncedMessageId(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Snapshot + announce, the whole tail of an import in one call.
     */
    public function afterImport(): string
    {
        return $this->announce($this->recordSnapshot());
    }
}
