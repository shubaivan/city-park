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

        try {
            $this->bot->sendMessage(
                text: $text,
                chat_id: (int)$this->residentChat->chatId(),
                parse_mode: ParseMode::HTML,
            );
        } catch (\Throwable $e) {
            $this->logger->error('debt announcement failed', [
                'snapshot_id' => $snapshot->getId(),
                'error' => $e->getMessage(),
            ]);

            return 'failed';
        }

        $snapshot->markAnnounced();
        $this->em->flush();

        $this->logger->info('debt announcement posted', [
            'snapshot_id' => $snapshot->getId(),
            'total' => $snapshot->getTotal(),
            'debtors' => $snapshot->getDebtors(),
        ]);

        return 'sent';
    }

    /**
     * Snapshot + announce, the whole tail of an import in one call.
     */
    public function afterImport(): string
    {
        return $this->announce($this->recordSnapshot());
    }
}
