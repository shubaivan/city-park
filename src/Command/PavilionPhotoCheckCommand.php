<?php

namespace App\Command;

use App\Entity\AccountStatusLog;
use App\Entity\PhotoUploadRequest;
use App\Entity\TelegramUser;
use App\Repository\PhotoUploadRequestRepository;
use App\Service\AccountStatusAuditor;
use App\Service\PavilionPhotoService;
use App\Service\SchedulePavilionService;
use App\Service\UkDateFormatter;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'pavilion:photo:check',
    description: 'Materializes photo-upload obligations, sends reminders and blocks accounts who miss them.',
)]
class PavilionPhotoCheckCommand extends Command
{
    public function __construct(
        private LoggerInterface $logger,
        private PavilionPhotoService $photoService,
        private PhotoUploadRequestRepository $requestRepository,
        private Nutgram $bot,
        private EntityManagerInterface $em,
        private AccountStatusAuditor $auditor,
        private LoggerInterface $photoLogger,
    ) {
        parent::__construct();
    }



    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $now = SchedulePavilionService::createNewDate();

        $this->log($io, sprintf('=== photo:check tick @ %s ===', $now->format('Y-m-d H:i:s P')));

        try {
            $this->logCensus($io, $now);
            $this->materializeRequests($io, $now);
            $this->processOpenRequests($io, $now);
            $this->log($io, 'tick complete');
        } catch (\Throwable $t) {
            $this->logger->error('pavilion:photo:check failed: ' . $t->getMessage(), ['exception' => $t]);
            $this->log($io, 'FAILED: ' . $t->getMessage());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function materializeRequests(SymfonyStyle $io, \DateTime $now): void
    {
        $sessions = $this->photoService->findSessionsNeedingPhotos($now);
        $created = 0;
        $reused = 0;
        $autoResolved = 0;

        foreach ($sessions as $s) {
            $result = $this->photoService->ensureRequest(
                $s['account'],
                $s['pavilion'],
                $s['start'],
                $s['end'],
            );
            $req = $result['request'];

            $tag = 'reused';
            if ($result['created']) {
                $tag = $result['preResolvedByPhoto'] ? 'created+auto-resolved' : 'created';
                $result['preResolvedByPhoto'] ? $autoResolved++ : $created++;

                // Structured chain event #1: obligation born. Lets photo-upload.log
                // alone tell the full per-request story when grepped by request_id
                // (materialized -> reminder1/2 -> blocked -> grace -> saved/unblocked).
                $this->photoLogger->info('request materialized', [
                    'request_id' => $req->getId(),
                    'account_id' => $s['account']->getId(),
                    'pavilion' => $s['pavilion'],
                    'session_start' => $s['start']->format('Y-m-d H:i'),
                    'session_end' => $s['end']->format('Y-m-d H:i'),
                    'block_at' => $this->photoService->blockAt($req)->format('Y-m-d H:i'),
                    'pre_resolved_by_photo' => $result['preResolvedByPhoto'],
                ]);
            } else {
                $reused++;
            }

            $this->log($io, sprintf(
                '  materialise: acc=%d pav=%d session=%s..%s -> req#%d (%s)',
                $s['account']->getId(),
                $s['pavilion'],
                $s['start']->format('Y-m-d H:i'),
                $s['end']->format('Y-m-d H:i'),
                $req->getId(),
                $tag,
            ));
        }

        $this->log($io, sprintf(
            'Materialise summary: %d sessions seen — %d new, %d reused, %d auto-resolved by existing photo',
            count($sessions),
            $created,
            $reused,
            $autoResolved,
        ));
    }

    private function processOpenRequests(SymfonyStyle $io, \DateTime $now): void
    {
        $open = $this->requestRepository->findOpen();
        $reminded = 0;
        $blocked = 0;
        $graceWarned = 0;
        $awaitingAdmin = 0;

        foreach ($open as $req) {
            $dueReminder = $this->photoService->dueReminderNumber($req, $now);
            if ($dueReminder !== null) {
                // Advance the escalation clock whether or not the reminder was
                // delivered. If we can't reach the user (no chat_id / bot blocked),
                // the photo obligation still stands — burning the reminder slot lets
                // the block eventually fire instead of this request looping here
                // forever. Enforcement must not depend on our ability to nudge.
                $delivered = $this->sendReminder($req, $dueReminder);
                $this->photoService->markReminderSent($req, $now);
                if ($delivered) {
                    $reminded++;
                }
                $this->log($io, sprintf(
                    '  reminder %d/%d %s: req#%d acc=%d session=%s',
                    $dueReminder,
                    count(PavilionPhotoService::REMINDER_OFFSETS_MIN),
                    $delivered ? 'sent' : 'undeliverable (slot advanced)',
                    $req->getId(),
                    $req->getAccount()->getId(),
                    $req->getSessionStartAt()->format('Y-m-d H:i'),
                ));
                continue;
            }

            if ($this->photoService->shouldBlock($req, $now)) {
                $this->blockAccount($req, $now);
                $blocked++;
                $this->log($io, sprintf(
                    '  BLOCKED: req#%d acc=%d session=%s pav=%d',
                    $req->getId(),
                    $req->getAccount()->getId(),
                    $req->getSessionStartAt()->format('Y-m-d H:i'),
                    $req->getPavilion(),
                ));
                continue;
            }

            // Final nudge for an already-blocked user whose self-upload window is
            // about to close. Sent once; auto-moot once they upload (request resolves).
            if ($this->photoService->shouldGraceWarn($req, $now)) {
                if ($this->sendGraceWarning($req)) {
                    $this->photoService->markGraceWarningSent($req, $now);
                    $graceWarned++;
                    $this->log($io, sprintf(
                        '  GRACE-WARN: req#%d acc=%d session=%s pav=%d (cutoff %s)',
                        $req->getId(),
                        $req->getAccount()->getId(),
                        $req->getSessionStartAt()->format('Y-m-d H:i'),
                        $req->getPavilion(),
                        $this->photoService->uploadCutoffAt($req)->format('Y-m-d H:i'),
                    ));
                }
            }

            // No escalation action this tick — record where the obligation sits so a
            // future "why is req#X (not) blocked?" is answerable from any single tick:
            // reminders fired so far, the instant the block will/did fire, and (once
            // blocked) the self-upload cutoff. All times are Kyiv wall-clock.
            //
            // phase= names the lifecycle stage explicitly so the log is self-evident
            // without re-deriving it from the timestamps. EXPIRED-awaiting-admin is the
            // terminal stuck state (blocked, cutoff passed): the cron will never touch
            // it again, so it's the one to grep for. Uppercased to stand out.
            $phase = match (true) {
                !$req->isBlocked() && $req->getRemindersSent() < count(PavilionPhotoService::REMINDER_OFFSETS_MIN)
                    => 'awaiting-reminder',
                !$req->isBlocked() => 'awaiting-block',
                $this->photoService->isUploadStillAllowed($req, $now) => 'self-upload-window',
                default => 'EXPIRED-awaiting-admin',
            };
            if ($phase === 'EXPIRED-awaiting-admin') {
                $awaitingAdmin++;
            }
            $this->log($io, sprintf(
                '  waiting: req#%d acc=%d phase=%s session=%s pav=%d reminders=%d/%d blockAt=%s blocked_at=%s cutoff=%s',
                $req->getId(),
                $req->getAccount()->getId(),
                $phase,
                $req->getSessionStartAt()->format('Y-m-d H:i'),
                $req->getPavilion(),
                $req->getRemindersSent(),
                count(PavilionPhotoService::REMINDER_OFFSETS_MIN),
                $this->photoService->blockAt($req)->format('Y-m-d H:i'),
                $req->getBlockedAt() ? $req->getBlockedAt()->format('Y-m-d H:i') : '—',
                $req->isBlocked() ? $this->photoService->uploadCutoffAt($req)->format('Y-m-d H:i') : '—',
            ));
        }

        $this->log($io, sprintf(
            'Process summary: %d open requests — %d reminded, %d blocked, %d grace-warned, %d past cutoff awaiting admin',
            count($open),
            $reminded,
            $blocked,
            $graceWarned,
            $awaitingAdmin,
        ));
    }

    private function log(SymfonyStyle $io, string $line): void
    {
        // Kyiv, NOT server UTC: session times, blockAt and the tick header are all
        // Kyiv wall-clock, so the line timestamp must match or the log reads with a
        // confusing ~3h skew (prod runs on UTC). Keeps the whole chain on one clock.
        $stamp = (new \DateTime('now', new \DateTimeZone('Europe/Kyiv')))->format('Y-m-d H:i:s');
        $io->writeln(sprintf('[%s] %s', $stamp, $line));
    }

    /**
     * One-line snapshot of the global block state at the start of every tick.
     * Greppable over time (`grep census var/log/photo-check.log`) so a genuine
     * mass-block — or a sudden jump in inactive accounts — is visible at a glance
     * instead of having to reconstruct it from the live DB after the fact.
     */
    private function logCensus(SymfonyStyle $io, \DateTime $now): void
    {
        $count = fn(string $dql): int => (int) $this->em->createQuery($dql)->getSingleScalarResult();

        $total = $count('SELECT COUNT(a.id) FROM ' . \App\Entity\Account::class . ' a');
        // is_active false OR NULL both mean "cannot book"; count them together.
        $inactive = $count(
            'SELECT COUNT(a.id) FROM ' . \App\Entity\Account::class . ' a'
            . ' WHERE a.is_active = false OR a.is_active IS NULL'
        );
        $openTotal = $count(
            'SELECT COUNT(r.id) FROM ' . PhotoUploadRequest::class . ' r WHERE r.resolved_at IS NULL'
        );

        // Split the blocked-but-open requests by whether the self-upload grace window
        // is still open. A blocked request past its cutoff will NEVER resolve on its
        // own — the cron takes no further action and the bot refuses late uploads — so
        // it sits here until an admin acts. Lumping both states under one "in
        // self-upload window" label (the old wording) hid exactly the cohort that needs
        // human attention. Partitioned in PHP because uploadCutoffAt() carries
        // night-deferral logic that DQL can't express.
        $blockedOpen = array_filter(
            $this->requestRepository->findOpen(),
            fn(PhotoUploadRequest $r) => $r->isBlocked(),
        );
        $inWindow = 0;
        $awaitingAdmin = 0;
        foreach ($blockedOpen as $r) {
            $this->photoService->isUploadStillAllowed($r, $now) ? $inWindow++ : $awaitingAdmin++;
        }

        $this->log($io, sprintf(
            'census: accounts %d total — %d active, %d inactive; open photo requests %d'
            . ' (%d blocked: %d in self-upload window, %d past cutoff awaiting admin)',
            $total,
            $total - $inactive,
            $inactive,
            $openTotal,
            count($blockedOpen),
            $inWindow,
            $awaitingAdmin,
        ));
    }

    private function sendReminder(PhotoUploadRequest $req, int $reminderNumber): bool
    {
        $account = $req->getAccount();
        $start = $req->getSessionStartAt();
        $pavilionName = $req->getPavilion() === 1 ? 'Перша' : 'Друга';
        $totalReminders = count(PavilionPhotoService::REMINDER_OFFSETS_MIN);
        $blockAt = $this->photoService->blockAt($req);

        $heading = $reminderNumber === 1
            ? "📸 <b>Потрібне фото альтанки після бронювання</b>"
            : sprintf("📸 <b>Нагадування %d/%d — фото альтанки</b>", $reminderNumber, $totalReminders);

        $text = sprintf(
            "%s\n\n🏠 Альтанка: <b>%s</b>\n📅 <b>%s</b>\n⏰ <b>%s</b>\n\n"
            . "ℹ️ Після кожного бронювання ОСББ просить надіслати <b>одне фото альтанки</b>, "
            . "щоб переконатися, що вона прибрана і не пошкоджена. Це обовʼязкова умова користування.\n\n"
            . "👉 Просто надішліть фото у цей чат — ми автоматично прикріпимо його до бронювання, "
            . "вказаного вище.\n\n"
            . "📝 <i>Якщо сьогодні у вас було кілька окремих сесій (наприклад, вранці і ввечері або у різних альтанках), "
            . "фото потрібно надіслати окремо для кожної сесії — переходьте в меню «📸 Завантажити фото», там видно усі відкриті запити.</i>\n\n"
            . "⏳ Будь ласка, надішліть фото найближчим часом — орієнтовно до "
            . "<b>%s</b> (%s). Інакше акаунт буде тимчасово заблоковано до зʼясування.",
            $heading,
            $pavilionName,
            UkDateFormatter::dayDate($start),
            UkDateFormatter::time($start),
            UkDateFormatter::time($blockAt),
            UkDateFormatter::dayDate($blockAt),
        );

        $any = false;
        foreach ($account->getUsers() as $user) {
            /** @var TelegramUser $user */
            if (!$user->getChatId()) {
                $this->photoLogger->warning('reminder not delivered: user has no chat_id', [
                    'request_id' => $req->getId(),
                    'account_id' => $account->getId(),
                    'user_id' => $user->getId(),
                ]);
                continue;
            }
            try {
                $this->bot->sendMessage(
                    text: $text,
                    chat_id: $user->getChatId(),
                    parse_mode: ParseMode::HTML,
                );
                $any = true;
                $this->photoLogger->info('reminder delivered', [
                    'request_id' => $req->getId(),
                    'account_id' => $account->getId(),
                    'chat_id' => $user->getChatId(),
                    'reminder' => $reminderNumber,
                ]);
            } catch (\Throwable $t) {
                $this->logger->warning('photo reminder send failed', [
                    'user_id' => $user->getId(),
                    'chat_id' => $user->getChatId(),
                    'error' => $t->getMessage(),
                ]);
                $this->photoLogger->error('reminder NOT delivered (Telegram error)', [
                    'request_id' => $req->getId(),
                    'account_id' => $account->getId(),
                    'chat_id' => $user->getChatId(),
                    'reminder' => $reminderNumber,
                    'error' => $t->getMessage(),
                ]);
            }
        }

        return $any;
    }

    private function blockAccount(PhotoUploadRequest $req, \DateTime $now): void
    {
        $account = $req->getAccount();
        $wasActive = $account->isActive();
        $account->setIsActive(false);
        if ($wasActive) {
            $this->auditor->log(
                $account, true, false,
                AccountStatusLog::SOURCE_PHOTO_CHECK,
                'photo',
                sprintf('photo_request_id=%d, session=%s @ pav %d',
                    $req->getId(),
                    $req->getSessionStartAt()->format('Y-m-d H:i'),
                    $req->getPavilion()
                ),
            );
        }
        $this->photoService->markBlocked($req, $now);
        $this->em->flush();

        // Structured chain event #2: the block itself — logged here (before the
        // notice loop) so it lands even when the user has no chat_id and never gets
        // a block notice. 'was_active' shows whether this flipped is_active or the
        // account was already inactive (e.g. debt). Pairs with 'request materialized'
        // and the upload events under the same request_id.
        $this->photoLogger->warning('account blocked (photo missing)', [
            'request_id' => $req->getId(),
            'account_id' => $account->getId(),
            'pavilion' => $req->getPavilion(),
            'session_start' => $req->getSessionStartAt()->format('Y-m-d H:i'),
            'session_end' => $req->getSessionEndAt()->format('Y-m-d H:i'),
            'reminders_sent' => $req->getRemindersSent(),
            'blocked_at' => $now->format('Y-m-d H:i:s'),
            'upload_cutoff' => $this->photoService->uploadCutoffAt($req)->format('Y-m-d H:i'),
            'was_active' => (bool)$wasActive,
        ]);

        $start = $req->getSessionStartAt();
        $cutoff = $this->photoService->uploadCutoffAt($req);
        $text = sprintf(
            "⛔ <b>Ваш аккаунт заблоковано</b>\n\nПричина: не завантажено фото альтанки після бронювання:\n📅 <b>%s</b>\n⏰ <b>%s</b>\n🏠 Альт. <b>%d</b>\n\n"
            . "📸 <i>У вас ще є <b>%s</b> — до <b>%s</b> — щоб надіслати фото в цей чат, і блокування зніметься автоматично.</i>\n\n"
            . "Після цього — лише через Аліну Бухгалтера: +380 93 658 32 02.",
            UkDateFormatter::dayDate($start),
            UkDateFormatter::time($start),
            $req->getPavilion(),
            PavilionPhotoService::uploadGraceLabel(),
            $cutoff->format('H:i'),
        );

        foreach ($account->getUsers() as $user) {
            /** @var TelegramUser $user */
            if (!$user->getChatId()) {
                $this->photoLogger->warning('block-notice not delivered: user has no chat_id', [
                    'request_id' => $req->getId(),
                    'account_id' => $account->getId(),
                    'user_id' => $user->getId(),
                ]);
                continue;
            }
            try {
                $this->bot->sendMessage(
                    text: $text,
                    chat_id: $user->getChatId(),
                    parse_mode: ParseMode::HTML,
                );
                $this->photoLogger->info('block-notice delivered', [
                    'request_id' => $req->getId(),
                    'account_id' => $account->getId(),
                    'chat_id' => $user->getChatId(),
                ]);
            } catch (\Throwable $t) {
                $this->logger->warning('photo block-notice send failed', [
                    'user_id' => $user->getId(),
                    'error' => $t->getMessage(),
                ]);
                $this->photoLogger->error('block-notice NOT delivered (Telegram error)', [
                    'request_id' => $req->getId(),
                    'account_id' => $account->getId(),
                    'chat_id' => $user->getChatId(),
                    'error' => $t->getMessage(),
                ]);
            }
        }

        $this->logger->info(sprintf(
            'Account %d blocked for missing pavilion photo (request %d, session %s)',
            $account->getId(),
            $req->getId(),
            $start->format('Y-m-d H:i'),
        ));
    }

    /**
     * Final "self-upload window is almost over" nudge for an already-blocked
     * account. Sent to every linked user once. Returns true if at least one
     * message was delivered (so the caller marks it sent and won't repeat).
     */
    private function sendGraceWarning(PhotoUploadRequest $req): bool
    {
        $account = $req->getAccount();
        $start = $req->getSessionStartAt();
        $cutoff = $this->photoService->uploadCutoffAt($req);

        $text = sprintf(
            "⏳ <b>Залишилось мало часу, щоб розблокуватися самостійно</b>\n\n"
            . "Фото за бронювання ще не надіслано:\n📅 <b>%s</b>\n⏰ <b>%s</b>\n🏠 Альт. <b>%d</b>\n\n"
            . "📸 Надішліть фото в цей чат до <b>%s</b> (залишилось менше ніж %d хв) — блокування зніметься автоматично.\n\n"
            . "⛔ Після цього самостійне завантаження буде вимкнено, і розблокувати акаунт можна буде лише через Аліну Бухгалтера: +380 93 658 32 02.",
            UkDateFormatter::dayDate($start),
            UkDateFormatter::time($start),
            $req->getPavilion(),
            $cutoff->format('H:i'),
            PavilionPhotoService::GRACE_WARNING_BEFORE_CUTOFF_MIN,
        );

        $any = false;
        foreach ($account->getUsers() as $user) {
            /** @var TelegramUser $user */
            if (!$user->getChatId()) {
                $this->photoLogger->warning('grace-warning not delivered: user has no chat_id', [
                    'request_id' => $req->getId(),
                    'account_id' => $account->getId(),
                    'user_id' => $user->getId(),
                ]);
                continue;
            }
            try {
                $this->bot->sendMessage(
                    text: $text,
                    chat_id: $user->getChatId(),
                    parse_mode: ParseMode::HTML,
                );
                $any = true;
                $this->photoLogger->info('grace-warning delivered', [
                    'request_id' => $req->getId(),
                    'account_id' => $account->getId(),
                    'chat_id' => $user->getChatId(),
                ]);
            } catch (\Throwable $t) {
                $this->logger->warning('photo grace-warning send failed', [
                    'user_id' => $user->getId(),
                    'error' => $t->getMessage(),
                ]);
                $this->photoLogger->error('grace-warning NOT delivered (Telegram error)', [
                    'request_id' => $req->getId(),
                    'account_id' => $account->getId(),
                    'chat_id' => $user->getChatId(),
                    'error' => $t->getMessage(),
                ]);
            }
        }

        return $any;
    }
}
