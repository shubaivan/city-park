<?php

namespace App\Telegram\Debt\Command;

use App\Service\DebtBoardService;
use App\Service\TelegramUserService;
use App\Telegram\Start\Command\StartCommand;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

/**
 * "💸 Звіт боржників" — the full list behind the main menu's board.
 *
 * All the judgement lives in DebtBoardService; this is the Telegram half. The viewer's
 * Account is resolved here and handed down, because the report is for verified
 * residents only and the service must never have to guess who is asking.
 */
class DebtBoardCommand
{
    public const MENU_CALLBACK = 'debt-board';

    public function __construct(
        private TelegramUserService $telegramUserService,
        private DebtBoardService $board,
    ) {}

    public function __invoke(Nutgram $bot): void
    {
        $user = $this->telegramUserService->getCurrentUser();
        $account = $user ? $this->telegramUserService->resolveAccount($user) : null;

        $text = $this->board->report($account);
        $markup = InlineKeyboardMarkup::make()->addRow(StartCommand::homeButton());

        if ($bot->isCallbackQuery()) {
            try {
                $bot->editMessageText(text: $text, parse_mode: ParseMode::HTML, reply_markup: $markup);

                return;
            } catch (\Throwable) {
                // A photo card cannot be edited into text — fall through to a new message.
            }
        }

        $bot->sendMessage(text: $text, parse_mode: ParseMode::HTML, reply_markup: $markup);
    }
}
