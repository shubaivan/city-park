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
        }

        $this->log($io, sprintf(
            'Process summary: %d open requests — %d reminded, %d blocked, %d grace-warned',
            count($open),
            $reminded,
            $blocked,
            $graceWarned,
        ));
    }

    private function log(SymfonyStyle $io, string $line): void
    {
        $io->writeln(sprintf('[%s] %s', (new \DateTime())->format('Y-m-d H:i:s'), $line));
    }

    private function sendReminder(PhotoUploadRequest $req, int $reminderNumber): bool
    {
        $account = $req->getAccount();
        $start = $req->getSessionStartAt();
        $pavilionName = $req->getPavilion() === 1 ? 'Перша' : 'Друга';
        $totalReminders = count(PavilionPhotoService::REMINDER_OFFSETS_MIN);
        $blockAfterMin = PavilionPhotoService::BLOCK_AFTER_MIN;

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
            . "⛔ Якщо фото не буде надіслано протягом <b>%d хв</b> після завершення бронювання, "
            . "акаунт буде тимчасово заблоковано до зʼясування.",
            $heading,
            $pavilionName,
            UkDateFormatter::dayDate($start),
            UkDateFormatter::time($start),
            $blockAfterMin,
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
