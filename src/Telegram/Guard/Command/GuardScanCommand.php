<?php

namespace App\Telegram\Guard\Command;

use App\Entity\Account;
use App\Repository\AccountRepository;
use App\Service\GuardService;
use App\Service\SchedulePavilionService;
use App\Service\TelegramUserService;
use App\Telegram\Start\Command\StartCommand;
use Psr\Log\LoggerInterface;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

/**
 * The other end of the QR: `/start g-<account>-<signature>`, arriving because a guard
 * pointed a camera at a resident's phone.
 *
 * Registered as `start {payload}`, which Nutgram anchors — a bare `/start` still reaches
 * StartCommand, and this only ever sees a deep link.
 *
 * **Any confirmed resident may scan, not only the guard** (Иван, 09.09.2026: «я хотел бы
 * чтоб кто угодно из ЖК мог проверить кого угодно, а не только охрана»). There are two
 * guards and 457 residents, and «ці люди тут по броні?» is asked by whoever happens to be
 * standing in the yard. An unlinked visitor still gets nothing — the same line the debtors'
 * board and the complaints register draw.
 *
 * **One answer, the same for everybody who may read it.** The guard's version and the
 * neighbour's were briefly different — the flat only for him — and that was one rule too
 * many: the code is shown deliberately, by the person it belongs to, to somebody they are
 * standing in front of, and «це мешканець» without saying *which* flat answers nothing a
 * neighbour could not already see. Иван's call, the same evening. So there is one text,
 * and no branch that could ever leak the wrong half to the wrong reader.
 *
 * Answers:
 *
 * - **unlinked** → «код читають підтверджені мешканці», and nothing about the holder;
 * - **anybody else** → ✅ the flat, plus the booking if one is running, or a plain «броні
 *   на зараз немає» — the answer that matters either way, because a screenshot from last
 *   Saturday must read as wrong rather than as a bot error.
 *
 * A bad signature is treated as a stranger, not as an error: `readToken()` returns null and
 * the payload never reaches the database.
 */
class GuardScanCommand
{
    public function __construct(
        private GuardService $guard,
        private TelegramUserService $telegramUserService,
        private AccountRepository $accounts,
        private ?LoggerInterface $chatLogger = null,
    ) {}

    public function __invoke(Nutgram $bot, string $payload = ''): void
    {
        $user = $this->telegramUserService->getCurrentUser();
        $viewerAccount = $user ? $this->telegramUserService->resolveAccount($user) : null;

        if (!$this->guard->mayScan($user, $viewerAccount)) {
            // An unlinked visitor, or a forwarded screenshot opened by somebody outside the
            // house: told what the code is and how to become someone who can read it, never
            // anything about whose code it is.
            $bot->sendMessage(
                text: "🔒 <b>QR-код мешканця ЖК</b>\n\n"
                    . "Його читають підтверджені мешканці — щоб перевірити, чи людина "
                    . "справді з нашого ЖК і чи є в неї бронь на альтанку.\n\n"
                    . 'Ви ще не підтверджені: натисніть /phone і поділіться номером телефону.',
                parse_mode: ParseMode::HTML,
                reply_markup: InlineKeyboardMarkup::make()->addRow(StartCommand::homeButton()),
            );

            return;
        }

        $accountId = $this->guard->readToken(trim($payload));
        $account = $accountId !== null ? $this->accounts->find($accountId) : null;

        $this->chatLogger?->info('guard qr scanned', [
            'telegram_id' => $user?->getTelegramId(),
            'payload_valid' => $accountId !== null,
            'account_id' => $accountId,
        ]);

        if (!$account instanceof Account) {
            $this->answer($bot, "⚠️ <b>Код не розпізнано</b>\n\nПопросіть відкрити його в боті ще раз.");

            return;
        }

        $now = SchedulePavilionService::createNewDate();
        $session = $this->guard->runningSessionFor($account, $now);

        if ($session === null) {
            $this->answer($bot, sprintf(
                "✅ <b>Код дійсний</b>\n\nЦе мешканець нашого ЖК\n<b>%s</b>\n\n"
                    . '<i>Але броні на альтанку на зараз у них немає.</i>',
                htmlspecialchars($account->getPlaceLabel(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            ));

            return;
        }

        $this->answer($bot, sprintf(
            "✅ <b>Код дійсний</b>\n\nЦе мешканець нашого ЖК\n<b>%s</b>\n"
                . "🏛 Зараз бронь: %s альтанка · <b>%s–%s</b>\n\n<i>Перевірено о %s.</i>",
            htmlspecialchars(GuardService::place($session), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            SchedulePavilionService::pavilionName($session['pavilion']),
            $session['start']->format('H:i'),
            $session['end']->format('H:i'),
            $now->format('H:i'),
        ));
    }

    /**
     * The button under the answer goes to the board, which renders itself per reader — the
     * guard's names flats, everybody else's says «зайнято». One label, because at this
     * point the bot has already answered and the two boards are the same section.
     */
    private function answer(Nutgram $bot, string $text): void
    {
        $bot->sendMessage(
            text: $text,
            parse_mode: ParseMode::HTML,
            reply_markup: InlineKeyboardMarkup::make()->addRow(InlineKeyboardButton::make(
                '🏛 Альтанки зараз',
                callback_data: GuardCommand::MENU_CALLBACK,
            )),
        );
    }
}
