<?php

namespace App\Telegram\Debt\Command;

use App\Entity\Account;
use App\Service\DebtBoardService;
use App\Service\TelegramUserService;
use App\Telegram\Start\Command\StartCommand;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

/**
 * "💸 Звіт боржників" — the full list behind the main menu's board.
 *
 * All the judgement lives in DebtBoardService; this is the Telegram half. The viewer's
 * Account is resolved here and handed down, because the report is for verified
 * residents only and the service must never have to guess who is asking.
 *
 * Paged since 04.09.2026. The list is 149 flats long and used to be cut off wherever the
 * message ran out of room — which published the top forty and hid everyone else, so a
 * resident could never check the neighbour they were actually wondering about.
 */
class DebtBoardCommand
{
    public const MENU_CALLBACK = 'debt-board';

    public const PAGE_PREFIX = 'debt-board:page:';

    private const NOOP_CALLBACK = 'debt-board:noop';

    public function __construct(
        private TelegramUserService $telegramUserService,
        private DebtBoardService $board,
    ) {}

    public function __invoke(Nutgram $bot): void
    {
        $data = $bot->isCallbackQuery() ? (string)($bot->callbackQuery()->data ?? '') : '';

        if ($data === self::NOOP_CALLBACK) {
            $bot->answerCallbackQuery();

            return;
        }

        $page = str_starts_with($data, self::PAGE_PREFIX)
            ? (int)substr($data, strlen(self::PAGE_PREFIX))
            : 1;

        $user = $this->telegramUserService->getCurrentUser();
        $account = $user ? $this->telegramUserService->resolveAccount($user) : null;

        $text = $this->board->report($account, $page);
        $markup = $this->markup($account, $page);

        if ($bot->isCallbackQuery()) {
            $bot->answerCallbackQuery();

            try {
                $bot->editMessageText(text: $text, parse_mode: ParseMode::HTML, reply_markup: $markup);

                return;
            } catch (\Throwable) {
                // A photo card cannot be edited into text — fall through to a new message.
            }
        }

        $bot->sendMessage(text: $text, parse_mode: ParseMode::HTML, reply_markup: $markup);
    }

    private function markup(?Account $account, int $page): InlineKeyboardMarkup
    {
        $markup = InlineKeyboardMarkup::make();

        // No account means the report is the "this is for verified residents" notice, and
        // paging through a notice is nonsense.
        if (!$account instanceof Account) {
            return $markup->addRow(StartCommand::homeButton());
        }

        $pages = $this->board->pageCount();
        $page = max(1, min($page, $pages));

        if ($pages > 1) {
            $row = [];

            if ($page > 1) {
                $row[] = InlineKeyboardButton::make('⬅️', callback_data: self::PAGE_PREFIX . ($page - 1));
            }

            $row[] = InlineKeyboardButton::make(
                sprintf('%d/%d', $page, $pages),
                callback_data: self::NOOP_CALLBACK,
            );

            if ($page < $pages) {
                $row[] = InlineKeyboardButton::make('➡️', callback_data: self::PAGE_PREFIX . ($page + 1));
            }

            $markup->addRow(...$row);
        }

        // "Ви 63-й" is useless if reaching that line means tapping ➡️ four times.
        $mine = $this->board->pageOfViewer($account);

        if ($mine !== null && $mine !== $page) {
            $markup->addRow(InlineKeyboardButton::make(
                '📌 Моя квартира',
                callback_data: self::PAGE_PREFIX . $mine,
            ));
        }

        return $markup->addRow(StartCommand::homeButton());
    }
}
