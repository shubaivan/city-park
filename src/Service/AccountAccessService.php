<?php

namespace App\Service;

use App\Entity\Account;
use App\Entity\AccountStatusLog;
use App\Entity\TelegramUser;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;

/**
 * Blocking and unblocking an account by hand, with everything that has to happen alongside.
 *
 * `Account.is_active` is a single flag shared by three different mechanisms — debt, missed
 * pavilion photo, community vote — so flipping it by hand is never just a flip:
 *
 * - **the audit entry is not optional bookkeeping.** It is the only record of who blocked
 *   somebody and why, and the one path that skipped it (the web debt upload) took the house
 *   from 9 blocked accounts to 22 on 03.09.2026 with nothing in the log but unblocks;
 * - **an unblock must clear the vote window and forgive open photo requests**, or the next
 *   cron tick blocks the person again for the same thing;
 * - **the notice goes to every TelegramUser on the account**, not the row the admin happened
 *   to be looking at: a block applies to the flat, and the family member who booked is not
 *   necessarily the one being read on screen.
 *
 * All of it lived inline in the admin's giant JSON save endpoint. It moved here when the
 * resident card became a page with a small form per action — two copies of "what an unblock
 * entails" is how one screen quietly forgets to forgive a photo request.
 */
class AccountAccessService
{
    public function __construct(
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
        private Nutgram $bot,
        private PavilionPhotoService $photoService,
        private AccountStatusAuditor $auditor,
    ) {}

    /**
     * @return string|null an error for the admin, or null on success
     */
    public function block(Account $account, ?string $reason): ?string
    {
        // Mandatory: the audit log, the bot's message and the admin table all display the
        // cause, and "заблокований, причина невідома" is what makes a resident phone the
        // accountant instead of fixing the thing.
        if (!in_array($reason, ['debt', 'photo', 'other'], true)) {
            return 'Оберіть причину блокування (борг / фото / інша).';
        }

        if (!$account->isActive()) {
            return 'Акаунт уже заблокований.';
        }

        $account->setIsActive(false);
        $this->em->flush();

        $this->logger->info('Admin block', [
            'account_id' => $account->getId(),
            'account_number' => $account->getAccountNumber(),
            'reason' => $reason,
        ]);

        $this->auditor->log($account, true, false, AccountStatusLog::SOURCE_ADMIN, $reason);
        $this->em->flush();

        $this->notify($account, match ($reason) {
            'debt' => "⛔ <b>Ваш аккаунт заблоковано</b>\n\n"
                . "Причина: <b>борг</b> — сума перевищила персональний поріг (площа × тариф ОСББ × 1.5).\n\n"
                . 'Зверніться для розблокування до Аліни Бухгалтера (+380 93 658 32 02) або голови ОСББ Люди (+380 67 470 46 24).',
            'photo' => "⛔ <b>Ваш аккаунт заблоковано</b>\n\n"
                . "Причина: не завантажене фото після бронювання.\n\n"
                . 'Зверніться для розблокування до Аліни Бухгалтера (+380 93 658 32 02) або голови ОСББ Люди (+380 67 470 46 24).',
            default => "⛔ <b>Ваш аккаунт заблоковано</b>\n\n"
                . 'Зверніться для уточнення причини та розблокування до Аліни Бухгалтера (+380 93 658 32 02) або голови ОСББ Люди (+380 67 470 46 24).',
        }, 'block');

        return null;
    }

    /**
     * @return string|null an error for the admin, or null on success
     */
    public function unblock(Account $account, ?string $reason): ?string
    {
        if ($account->isActive()) {
            return 'Акаунт і так активний.';
        }

        $account->setIsActive(true);

        // An explicit admin unblock overrides an active community vote-block too, so the
        // 30-day window does not linger and re-gate the next debt or photo unblock.
        $account->setBlockedUntil(null);

        $forgiven = $this->photoService->forgiveBlockingRequests(
            $account,
            SchedulePavilionService::createNewDate(),
        );

        $this->em->flush();

        $this->logger->info('Admin unblock', [
            'account_id' => $account->getId(),
            'account_number' => $account->getAccountNumber(),
            'reason' => $reason ?: 'unspecified',
            'forgiven_photo_requests' => $forgiven,
        ]);

        $this->auditor->log(
            $account,
            false,
            true,
            AccountStatusLog::SOURCE_ADMIN,
            $reason ?: 'other',
            $forgiven > 0 ? sprintf('forgave %d open photo request(s)', $forgiven) : null,
        );
        $this->em->flush();

        $this->notify($account, match ($reason) {
            'photo' => "✅ <b>Доступ до бронювання відновлено.</b>\n\n"
                . 'Дякуємо за надіслане фото — обмеження знято. Можна знову бронювати.',
            'debt' => "✅ <b>Доступ до бронювання відновлено.</b>\n\n"
                . "Борг сплачено — обмеження знято. Можна знову бронювати.\n\n"
                . '<i>Нагадуємо: блок вмикається автоматично, якщо борг перевищить персональний поріг (площа × тариф ОСББ × 1.5, тобто 150% місячної плати).</i>',
            default => "✅ <b>Доступ до бронювання відновлено.</b>\n\nМожна знову бронювати.",
        }, 'unblock');

        return null;
    }

    /**
     * The notice reaches every person on the account.
     *
     * A block applies to the flat, and the family member who booked is not necessarily the
     * row the admin was looking at. Per-user failures are swallowed: one relative who never
     * started the bot must not fail the whole action.
     */
    private function notify(Account $account, string $text, string $kind): void
    {
        foreach ($account->getUsers() as $user) {
            /** @var TelegramUser $user */
            if (!$user->getChatId()) {
                continue;
            }

            try {
                $this->bot->sendMessage(
                    text: $text,
                    chat_id: $user->getChatId(),
                    parse_mode: ParseMode::HTML,
                );
            } catch (\Throwable $e) {
                $this->logger->error(sprintf('Failed to notify user about %s: %s', $kind, $e->getMessage()));
            }
        }
    }
}
