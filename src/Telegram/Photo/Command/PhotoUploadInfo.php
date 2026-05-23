<?php

namespace App\Telegram\Photo\Command;

use App\Entity\PhotoUploadRequest;
use App\Repository\PhotoUploadRequestRepository;
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

        $lines = ['📸 <b>Завантажте фото зовнішнього вигляду альтанки</b>', ''];
        $lines[] = 'Очікується завантаження для:';
        foreach ($open as $req) {
            $start = $req->getSessionStartAt();
            $lines[] = sprintf(
                '   🏠 Альт. %d · 📅 %s · ⏰ %s',
                $req->getPavilion(),
                UkDateFormatter::dayDate($start),
                UkDateFormatter::time($start),
            );
        }
        $lines[] = '';
        $lines[] = '<i>Просто надішліть фото у цей чат — і ми автоматично прикріпимо його до бронювання.</i>';

        $bot->sendMessage(
            text: implode("\n", $lines),
            parse_mode: ParseMode::HTML,
            reply_markup: InlineKeyboardMarkup::make()->addRow(StartCommand::homeButton()),
        );
    }
}
