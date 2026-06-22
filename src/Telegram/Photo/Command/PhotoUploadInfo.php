<?php

namespace App\Telegram\Photo\Command;

use App\Entity\PhotoUploadRequest;
use App\Repository\PhotoUploadRequestRepository;
use App\Service\PavilionPhotoService;
use App\Service\SchedulePavilionService;
use App\Service\TelegramUserService;
use App\Service\UkDateFormatter;
use App\Telegram\Start\Command\StartCommand;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

class PhotoUploadInfo
{
    public function __construct(
        private TelegramUserService $telegramUserService,
        private PhotoUploadRequestRepository $requestRepository,
        private PavilionPhotoService $photoService,
    ) {}

    public function __invoke(Nutgram $bot): void
    {
        $user = $this->telegramUserService->getCurrentUser();
        $account = $user?->getAccount();

        if (!$account) {
            $bot->sendMessage(
                text: '📷 У вас немає прив\'язаного аккаунту, тож завантаження не потрібне.',
                reply_markup: InlineKeyboardMarkup::make()->addRow(StartCommand::homeButton()),
            );
            return;
        }

        $open = $this->requestRepository->findOpenForAccount($account);
        $open = array_values(array_filter($open, fn(PhotoUploadRequest $r) => $r->isOpen()));

        if (!$open) {
            $bot->sendMessage(
                text: '✅ <b>У вас немає очікуючих завантажень фото.</b>',
                parse_mode: ParseMode::HTML,
                reply_markup: InlineKeyboardMarkup::make()->addRow(StartCommand::homeButton()),
            );
            return;
        }

        $now = SchedulePavilionService::createNewDate();
        $hasActive = false;
        $hasExpired = false;

        $lines = ['📸 <b>Завантажте фото зовнішнього вигляду альтанки</b>', ''];
        $lines[] = 'Очікується завантаження для:';
        foreach ($open as $req) {
            $start = $req->getSessionStartAt();
            $allowed = $this->photoService->isUploadStillAllowed($req, $now);
            $hasActive = $hasActive || $allowed;
            $hasExpired = $hasExpired || !$allowed;
            $lines[] = sprintf(
                '   %s 🏠 Альт. %d · 📅 %s · ⏰ %s',
                $allowed ? '🟢' : '❌',
                $req->getPavilion(),
                UkDateFormatter::dayDate($start),
                UkDateFormatter::time($start),
            );
        }
        $lines[] = '';
        if ($hasActive) {
            $lines[] = '<i>Просто надішліть фото у цей чат — і ми автоматично прикріпимо його до бронювання.</i>';
        }
        if ($hasExpired) {
            $lines[] = sprintf(
                '❌ — прострочено (минув час на завантаження — %s після блокування). Для розблокування зверніться до Аліни Бухгалтера (+380 93 658 32 02) або голови ОСББ Люди (+380 67 470 46 24).',
                PavilionPhotoService::uploadGraceLabel(),
            );
        }

        $bot->sendMessage(
            text: implode("\n", $lines),
            parse_mode: ParseMode::HTML,
            reply_markup: InlineKeyboardMarkup::make()->addRow(StartCommand::homeButton()),
        );
    }
}
