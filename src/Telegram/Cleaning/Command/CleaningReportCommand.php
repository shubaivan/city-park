<?php

namespace App\Telegram\Cleaning\Command;

use App\Service\CleaningStaffService;
use App\Service\TelegramUserService;
use App\Telegram\Start\Command\StartCommand;
use SergiX44\Nutgram\Handlers\Type\Command;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

/**
 * «📷 Фотозвіт» — the cleaning crew's one screen: how to send a report. The report itself
 * is just photos sent to the bot (PhotoUploadFlow routes staff to CleaningReportService).
 */
class CleaningReportCommand extends Command
{
    public const MENU_CALLBACK = 'cleaning-report';

    protected string $command = 'report';
    protected ?string $description = '📷 Фотозвіт';

    public const TEXT = "📷 <b>Фотозвіт</b>\n\n"
        . "Надішліть сюди фото виконаної роботи — одне або кілька одразу (альбомом).\n"
        . "Можна додати текст у підписі до фото: що і де прибрано.\n\n"
        . "Бот опублікує звіт у темі «🧹 Прибирання» чату мешканців з підписом «🧹 Клінінг» "
        . "та датою й часом, і відповість вам «✅ Фотозвіт надіслано».";

    public function __construct(
        private CleaningStaffService $staff,
        private TelegramUserService $users,
        $callable = null,
        ?string $command = null,
    ) {
        parent::__construct($callable, $command);
    }

    public function handle(Nutgram $bot): void
    {
        $this->render($bot);
    }

    public function __invoke(Nutgram $bot): mixed
    {
        if ($bot->isCallbackQuery()) {
            try {
                $bot->answerCallbackQuery();
            } catch (\Throwable) {
            }
        }

        $this->render($bot);

        return null;
    }

    private function render(Nutgram $bot): void
    {
        if (!$this->staff->isCleaning($this->users->getCurrentUser())) {
            StartCommand::send($bot, edit: $bot->isCallbackQuery());

            return;
        }

        $markup = InlineKeyboardMarkup::make()->addRow(StartCommand::homeButton());

        if ($bot->isCallbackQuery()) {
            try {
                $bot->editMessageText(text: self::TEXT, parse_mode: ParseMode::HTML, reply_markup: $markup);

                return;
            } catch (\Throwable) {
            }
        }

        $bot->sendMessage(text: self::TEXT, parse_mode: ParseMode::HTML, reply_markup: $markup);
    }
}
