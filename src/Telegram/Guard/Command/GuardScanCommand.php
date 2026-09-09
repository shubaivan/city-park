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
 * **The flat is in the guard's answer and in nobody else's.** His check *is* «яка
 * квартира»; a neighbour's is «чи це справді мешканець і чи є в них зараз бронь», and the
 * flat adds nothing to it. The difference is not squeamishness: the picture is forwardable
 * — one screenshot in the house chat would otherwise name a flat to 457 people, which is
 * exactly what `GuardService::board()`'s `namesFlats` switch exists to prevent.
 *
 * Answers:
 *
 * - **unlinked** → «код читають підтверджені мешканці», and nothing else;
 * - **guard** → ✅ the flat, the pavilion and the hours, or ❌ «броні на зараз немає»;
 * - **resident** → ✅ «код дійсний, це мешканець ЖК», plus the booking without the flat,
 *   or «броні на зараз немає» — the answer that matters either way, because a screenshot
 *   from last Saturday must read as plainly wrong rather than as a bot error.
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
        $isGuard = $this->guard->isGuard($user);

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
            $this->answer($bot, "⚠️ <b>Код не розпізнано</b>\n\nПопросіть відкрити його в боті ще раз.", $isGuard);

            return;
        }

        $now = SchedulePavilionService::createNewDate();
        $session = $this->guard->runningSessionFor($account, $now);

        if ($session === null) {
            $this->answer($bot, $isGuard ? sprintf(
                "❌ <b>Броні на зараз немає</b>\n\n%s\n\n"
                    . '<i>Код справжній, але на цю годину альтанка за цією квартирою не заброньована.</i>',
                htmlspecialchars($account->getPlaceLabel(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            ) : "✅ <b>Код дійсний</b>\n\nЦе мешканець нашого ЖК.\n\n"
                . '<i>Але броні на альтанку на зараз у них немає.</i>', $isGuard);

            return;
        }

        $this->answer($bot, $isGuard ? sprintf(
            "✅ <b>Все вірно</b>\n\n<b>%s</b>\n%s альтанка · <b>%s–%s</b>\n\n<i>Перевірено о %s.</i>",
            htmlspecialchars(GuardService::place($session), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            SchedulePavilionService::pavilionName($session['pavilion']),
            $session['start']->format('H:i'),
            $session['end']->format('H:i'),
            $now->format('H:i'),
        ) : sprintf(
            // No flat: see the note at the top. The hours are the whole answer to «вони
            // тут по броні?», and they are true of a household, not of a person.
            "✅ <b>Код дійсний</b>\n\nЦе мешканець нашого ЖК.\n"
                . "🏛 Зараз бронь: %s альтанка · <b>%s–%s</b>\n\n<i>Перевірено о %s.</i>",
            SchedulePavilionService::pavilionName($session['pavilion']),
            $session['start']->format('H:i'),
            $session['end']->format('H:i'),
            $now->format('H:i'),
        ), $isGuard);
    }

    /**
     * The button under the answer follows the reader, not the code: the guard's board names
     * flats and is his alone, so a resident is sent to their own version of it instead.
     */
    private function answer(Nutgram $bot, string $text, bool $isGuard): void
    {
        $bot->sendMessage(
            text: $text,
            parse_mode: ParseMode::HTML,
            reply_markup: InlineKeyboardMarkup::make()->addRow(InlineKeyboardButton::make(
                $isGuard ? '🛡 Хто зараз в альтанці' : '🏛 Альтанки зараз',
                callback_data: GuardCommand::MENU_CALLBACK,
            )),
        );
    }
}
