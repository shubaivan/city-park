<?php

namespace App\Command;

use App\Entity\PhotoUploadRequest;
use App\Entity\TelegramUser;
use App\Repository\PhotoUploadRequestRepository;
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

        foreach ($open as $req) {
            $dueReminder = $this->photoService->dueReminderNumber($req, $now);
            if ($dueReminder !== null) {
                if ($this->sendReminder($req, $dueReminder)) {
                    $this->photoService->markReminderSent($req, $now);
                    $reminded++;
                    $this->log($io, sprintf(
                        '  reminder %d/%d sent: req#%d acc=%d session=%s',
                        $dueReminder,
                        count(PavilionPhotoService::REMINDER_OFFSETS_MIN),
                        $req->getId(),
                        $req->getAccount()->getId(),
                        $req->getSessionStartAt()->format('Y-m-d H:i'),
                    ));
                }
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
            }
        }

        $this->log($io, sprintf(
            'Process summary: %d open requests — %d reminded, %d blocked',
            count($open),
            $reminded,
            $blocked,
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
                continue;
            }
            try {
                $this->bot->sendMessage(
                    text: $text,
                    chat_id: $user->getChatId(),
                    parse_mode: ParseMode::HTML,
                );
                $any = true;
            } catch (\Throwable $t) {
                $this->logger->warning('photo reminder send failed', [
                    'user_id' => $user->getId(),
                    'chat_id' => $user->getChatId(),
                    'error' => $t->getMessage(),
                ]);
            }
        }

        return $any;
    }

    private function blockAccount(PhotoUploadRequest $req, \DateTime $now): void
    {
        $account = $req->getAccount();
        $account->setIsActive(false);
        $this->photoService->markBlocked($req, $now);
        $this->em->flush();

        $start = $req->getSessionStartAt();
        $text = sprintf(
            "⛔ <b>Ваш аккаунт заблоковано</b>\n\nПричина: не завантажено фото альтанки після бронювання:\n📅 <b>%s</b>\n⏰ <b>%s</b>\n🏠 Альт. <b>%d</b>\n\nЗверніться до Аліни Бухгалтера для розблокування — +380 93 658 32 02.",
            UkDateFormatter::dayDate($start),
            UkDateFormatter::time($start),
            $req->getPavilion(),
        );

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
            } catch (\Throwable $t) {
                $this->logger->warning('photo block-notice send failed', [
                    'user_id' => $user->getId(),
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
}
