<?php

namespace App\Service;

use App\Service\OsbbContacts;
use App\Entity\Account;
use App\Entity\PhotoUploadRequest;
use App\Repository\PhotoUploadRequestRepository;
use Psr\Log\LoggerInterface;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;

/**
 * Everything that happens when a resident sends a photo to the bot: pick the
 * obligation it belongs to, download + attach it, answer the user.
 *
 * Lives in a service (not in the Nutgram handler) because there are two entry
 * points into the same flow — the global onPhoto handler
 * (App\Telegram\Photo\Command\UploadPhotoCommand) and the booking conversation,
 * which receives every update from a user with an active conversation and must
 * therefore be able to process a photo itself instead of dropping it.
 */
class PhotoUploadFlow
{
    public function __construct(
        private TelegramUserService $telegramUserService,
        private PhotoUploadRequestRepository $requestRepository,
        private PavilionPhotoService $photoService,
        private LoggerInterface $logger,
        private LoggerInterface $photoLogger,
    ) {}

    /**
     * Entry point for a Nutgram conversation that got a photo instead of the input its
     * current step expected. Nutgram routes EVERY update from a user with an active
     * conversation into that conversation, so without this the photo is swallowed and
     * the resident is blocked for a photo they did send.
     *
     * Ends the conversation, then saves the photo right away — the obligation window is
     * only ~1 hour wide, so "please resend" is not good enough (and the resend used to
     * land in the very same stuck conversation).
     *
     * Guarantees it never throws: an exception here would make /hook answer 500 and
     * Telegram would retry the same photo update for an hour (incidents 02–03.08
     * and 16.08.2026, ~950 failed deliveries, 3 residents wrongly blocked).
     */
    public function interceptConversationPhoto(
        Nutgram $bot,
        ?string $step,
        string $pausedNotice,
        ?string $keptNotice = null,
    ): void {
        // A photo can only be pavilion evidence if there is a pavilion booking behind it.
        // With none, cancelling what the resident was in the middle of writing costs them
        // their draft and buys nothing — which is exactly what happened on 02.09.2026 to
        // somebody filing a complaint who attached the photo instead of typing.
        //
        // This does not weaken the invariant that a photo sent to the bot is pavilion
        // evidence. The case that invariant protects — a session that ended before the
        // 20-minute cron materialised its request — requires a session to exist, and
        // hasPavilionContext() looks for exactly that, over the same lookback window.
        if (!$this->hasPavilionContext()) {
            $this->photoLogger->info('photo during conversation ignored: no pavilion booking to attach it to', [
                'chat_id' => $bot->chatId(),
                'telegram_user_id' => $bot->userId(),
                'step' => $step,
            ]);

            try {
                $bot->sendMessage(
                    text: $keptNotice ?? '📷 Фото тут не потрібне — у вас немає бронювань альтанки, '
                        . 'до яких його прикріпити. Продовжуйте, будь ласка: надішліть відповідь текстом.',
                    parse_mode: ParseMode::HTML,
                );
            } catch (\Throwable $e) {
                $this->photoLogger->error('failed to send kept-conversation notice', [
                    'chat_id' => $bot->chatId(),
                    'error' => $e->getMessage(),
                ]);
            }

            return;
        }

        $this->photoLogger->warning('photo received during active booking conversation — ending it and saving the photo inline', [
            'chat_id' => $bot->chatId(),
            'telegram_user_id' => $bot->userId(),
            'step' => $step,
        ]);

        // NB: $bot->endConversation() and NOT Conversation::end() — end() reads
        // $this->bot, which Conversation initialises only inside parent::__invoke()
        // and strips in __serialize(), so on a cache-restored conversation it threw.
        try {
            $bot->endConversation();
        } catch (\Throwable $e) {
            $this->photoLogger->error('failed to end conversation on incoming photo', [
                'chat_id' => $bot->chatId(),
                'error' => $e->getMessage(),
            ]);
        }

        try {
            $bot->sendMessage(text: $pausedNotice, parse_mode: ParseMode::HTML);
        } catch (\Throwable $e) {
            $this->photoLogger->error('failed to send paused-conversation notice', [
                'chat_id' => $bot->chatId(),
                'error' => $e->getMessage(),
            ]);
        }

        try {
            $this->process($bot);
        } catch (\Throwable $e) {
            $this->photoLogger->error('inline photo processing failed', [
                'chat_id' => $bot->chatId(),
                'error' => $e->getMessage(),
            ]);
            try {
                $bot->sendMessage(text: '⚠️ Не вдалося зберегти фото. Будь ласка, надішліть його ще раз.');
            } catch (\Throwable) {
                // nothing left to do — must never bubble up into a webhook 500
            }
        }
    }

    public function process(Nutgram $bot): void
    {
        $chatId = $bot->chatId();
        $user = $this->telegramUserService->getCurrentUser();
        $account = $user?->getAccount();

        $this->photoLogger->info('photoEvent received', [
            'chat_id' => $chatId,
            'telegram_user_id' => $user?->getId(),
            'account_id' => $account?->getId(),
        ]);

        if (!$account) {
            $this->photoLogger->info('photoEvent ignored: no linked account', ['chat_id' => $chatId]);
            $bot->sendMessage(
                text: '📷 Дякуємо, але у вас немає прив\'язаного аккаунту, тож завантаження зараз не потрібне.',
            );
            return;
        }

        $now = SchedulePavilionService::createNewDate();

        $open = $this->requestRepository->findOpenForAccount($account);
        $open = array_values(array_filter($open, fn(PhotoUploadRequest $r) => $r->isOpen()));

        if (!$open) {
            $pendingSession = $this->firstUnfinishedSession($account, $now);

            if ($pendingSession !== null) {
                // The obligation only materializes once the session is over, so a photo
                // sent before that has nothing to attach to. Saying "фото вже отримано"
                // here (as we used to) reads as "photo accepted" and the resident never
                // sends the real one.
                $this->photoLogger->info('photoEvent: no open requests yet — session still running', [
                    'account_id' => $account->getId(),
                    'session_start' => $pendingSession['start']->format('Y-m-d H:i'),
                    'session_end' => $pendingSession['end']->format('Y-m-d H:i'),
                ]);
                $bot->sendMessage(
                    text: sprintf(
                        "📷 <b>Фото ще зарано.</b>\n\n"
                        . "Ваше бронювання (%s, %s — до %s) ще не завершилось, тож фото поки немає до чого прикріпити.\n\n"
                        . "Будь ласка, надішліть фото <b>після завершення бронювання</b> — після %s. Тоді ми його приймемо.",
                        $pendingSession['pavilion'] === 1 ? 'перша альтанка' : 'друга альтанка',
                        UkDateFormatter::dayDate($pendingSession['start']),
                        UkDateFormatter::time($pendingSession['end']),
                        UkDateFormatter::time($pendingSession['end']),
                    ),
                    parse_mode: ParseMode::HTML,
                );
                return;
            }

            // Two very different situations used to share one message. "Фото вже отримано"
            // is true for somebody who booked and already sent their photo; said to
            // somebody with no booking at all it answers a question they did not ask —
            // received *what*? — and tells them nothing about what to do with the picture
            // they were actually trying to send.
            if ($this->hasRecentSession($account, $now)) {
                $this->photoLogger->info('photoEvent: no open requests to attach', [
                    'account_id' => $account->getId(),
                ]);
                $bot->sendMessage(
                    text: '📷 <b>Фото вже отримано.</b> Достатньо одного фото на сесію — наступне фото знадобиться лише після нового бронювання.',
                    parse_mode: ParseMode::HTML,
                );

                return;
            }

            $this->photoLogger->info('photoEvent: no booking at all — nothing to attach', [
                'account_id' => $account->getId(),
            ]);
            $bot->sendMessage(
                text: "📷 <b>Це фото нікуди не збережено.</b>\n\n"
                    . "У вас немає бронювань альтанки, тож фото альтанки зараз не потрібне.\n\n"
                    . '<i>Якщо ви хотіли додати фото до заявки — відкрийте «🔧 Заявки», '
                    . 'виберіть свою і натисніть «📷 Додати фото».</i>',
                parse_mode: ParseMode::HTML,
            );

            return;
        }

        $active = array_values(array_filter(
            $open,
            fn(PhotoUploadRequest $r) => $this->photoService->isUploadStillAllowed($r, $now),
        ));

        if (!$active) {
            $this->photoLogger->warning('photoEvent rejected: all open requests past upload cutoff', [
                'account_id' => $account->getId(),
                'open_request_ids' => array_map(fn(PhotoUploadRequest $r) => $r->getId(), $open),
            ]);
            $bot->sendMessage(
                text: '⏰ <b>Час на завантаження фото минув.</b>' . "\n\n"
                    . sprintf(
                        'Фото приймається лише протягом %s після блокування. '
                        . OsbbContacts::askThem('Для розблокування зверніться:')
                        . "\nабо до розробника @shubaivan.",
                        PavilionPhotoService::uploadGraceLabel(),
                    ),
                parse_mode: ParseMode::HTML,
            );
            return;
        }

        usort($active, fn(PhotoUploadRequest $a, PhotoUploadRequest $b) =>
            $a->getSessionStartAt() <=> $b->getSessionStartAt());
        $request = $active[0];

        $wasBlocked = $request->getBlockedAt() !== null && $account->isActive() === false;

        try {
            $this->saveLargestPhoto($bot, $request);
        } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException $t) {
            // Race: another photo for this session arrived between our request lookup and save.
            $this->photoLogger->info('photoEvent: duplicate (photo already attached this session)', [
                'account_id' => $account->getId(),
                'request_id' => $request->getId(),
            ]);
            $bot->sendMessage(
                text: '📷 <b>Фото вже отримано.</b> Достатньо одного фото на сесію.',
                parse_mode: ParseMode::HTML,
            );
            return;
        } catch (\Throwable $t) {
            $this->logger->error('Photo upload failed: ' . $t->getMessage(), [
                'exception' => $t,
                'request_id' => $request->getId(),
            ]);
            $this->photoLogger->error('photoEvent: save failed', [
                'account_id' => $account->getId(),
                'request_id' => $request->getId(),
                'error' => $t->getMessage(),
            ]);
            $bot->sendMessage(
                text: '⚠️ Не вдалося зберегти фото. Спробуйте надіслати ще раз.',
            );
            return;
        }

        $this->photoLogger->info('photoEvent: photo saved', [
            'account_id' => $account->getId(),
            'request_id' => $request->getId(),
            'pavilion' => $request->getPavilion(),
            'session_start' => $request->getSessionStartAt()->format('Y-m-d H:i'),
            'auto_unblocked' => $wasBlocked && $account->isActive() === true,
        ]);

        $start = $request->getSessionStartAt();
        $bot->sendMessage(
            text: sprintf(
                "✅ <b>Дякуємо! Фото отримано.</b>\n\n🏠 Альтанка: <b>%s</b>\n📅 <b>%s</b>\n⏰ <b>%s</b>",
                $request->getPavilion() === 1 ? 'Перша' : 'Друга',
                UkDateFormatter::dayDate($start),
                UkDateFormatter::time($start),
            ),
            parse_mode: ParseMode::HTML,
        );

        if ($wasBlocked && $account->isActive() === true) {
            $bot->sendMessage(
                text: '✅ <b>Доступ до бронювання відновлено</b> — оскільки ви завантажили фото, ми зняли блокування з вашого акаунту. Можна знову бронювати.',
                parse_mode: ParseMode::HTML,
            );
        }

        // Re-query: attachPhoto auto-resolves sibling open requests covered by the
        // photo's window, so the post-upload "still pending" count must reflect that.
        $stillOpen = array_filter(
            $this->requestRepository->findOpenForAccount($account),
            fn(PhotoUploadRequest $r) => $r->isOpen() && $this->photoService->isUploadStillAllowed($r, $now),
        );
        if ($stillOpen) {
            $bot->sendMessage(
                text: sprintf(
                    'У вас ще %d очікування завантаження фото. Будь ласка, надішліть наступне фото.',
                    count($stillOpen),
                ),
            );
        }
    }

    /**
     * Earliest booked session of this account that has not ended yet (today or later).
     * Used to tell a resident who photographed too early *when* the photo is expected,
     * instead of implying it was already accepted.
     *
     * @return array{pavilion:int, start:\DateTime, end:\DateTime}|null
     */
    /**
     * Is there any pavilion booking this photo could belong to?
     *
     * Open obligation, a session still running, or one that ended inside the lookback
     * window (the gap in which the 20-minute cron has not yet materialised its request).
     * Never throws and answers "yes" when unsure: treating a photo as pavilion evidence
     * by mistake is recoverable, ignoring real evidence is not.
     */
    private function hasPavilionContext(): bool
    {
        try {
            $account = $this->telegramUserService->getCurrentUser()?->getAccount();

            if (!$account instanceof Account) {
                return false;
            }

            $now = SchedulePavilionService::createNewDate();

            foreach ($this->requestRepository->findOpenForAccount($account) as $request) {
                if ($request->isOpen()) {
                    return true;
                }
            }

            return $this->hasRecentSession($account, $now)
                || $this->firstUnfinishedSession($account, $now) !== null;
        } catch (\Throwable $e) {
            $this->photoLogger->error('pavilion context check failed — assuming there is one', [
                'error' => $e->getMessage(),
            ]);

            return true;
        }
    }

    /** A session that ended within the lookback window, i.e. one that may still owe a photo. */
    private function hasRecentSession(Account $account, \DateTime $now): bool
    {
        $from = (clone $now)->modify('-' . PavilionPhotoService::LOOKBACK_HOURS . ' hours');

        foreach ($this->photoService->detectSessions($account, $from, clone $now) as $session) {
            if ($session['end'] <= $now) {
                return true;
            }
        }

        return false;
    }

    private function firstUnfinishedSession(Account $account, \DateTime $now): ?array
    {
        $from = (clone $now)->modify('-' . PavilionPhotoService::LOOKBACK_HOURS . ' hours');
        $until = (clone $now)->modify('+7 days');

        foreach ($this->photoService->detectSessions($account, $from, $until) as $session) {
            if ($session['end'] > $now) {
                return [
                    'pavilion' => $session['pavilion'],
                    'start' => $session['start'],
                    'end' => $session['end'],
                ];
            }
        }

        return null;
    }

    private function saveLargestPhoto(Nutgram $bot, PhotoUploadRequest $request): void
    {
        $message = $bot->message();
        $photos = $message?->photo ?? [];
        if (!$photos) {
            throw new \RuntimeException('No photo array in message');
        }

        // Telegram sends multiple sizes; take the largest.
        $largest = end($photos);
        $fileId = $largest->file_id;

        $file = $bot->getFile($fileId);
        if (!$file) {
            throw new \RuntimeException('Telegram getFile returned null');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'pavphoto_');
        if ($tmp === false) {
            throw new \RuntimeException('Failed to allocate temp file');
        }

        $ok = $bot->downloadFile($file, $tmp);
        if (!$ok) {
            @unlink($tmp);
            throw new \RuntimeException('downloadFile failed');
        }

        $ext = 'jpg';
        if (!empty($file->file_path)) {
            $ext = pathinfo($file->file_path, PATHINFO_EXTENSION) ?: 'jpg';
        }

        $this->photoService->attachPhoto(
            $request,
            $this->telegramUserService->getCurrentUser(),
            $tmp,
            $fileId,
            $ext,
        );
    }
}
