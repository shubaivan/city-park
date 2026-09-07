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
 * Three answers and no others:
 *
 * - **not a guard** → «цей код зчитує охорона», and nothing about the flat. A resident who
 *   scans their own code, or anybody who is forwarded the picture, learns nothing;
 * - **valid, and the booking is running** → ✅ with the flat, the pavilion and the hours;
 * - **valid, but nothing is running** → ❌ said plainly. This is the answer that matters:
 *   an expired screenshot from last Saturday must read as clearly wrong, not as an error
 *   the guard might blame on the bot.
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

        if (!$this->guard->isGuard($user)) {
            // Deliberately the same answer for a resident, a stranger and a forwarded
            // screenshot: none of them may learn whose code it is.
            $bot->sendMessage(
                text: "🔒 Цей QR-код зчитує охорона ЖК.\n\n"
                    . 'Якщо ви мешканець — просто покажіть код охоронцю.',
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
                "❌ <b>Броні на зараз немає</b>\n\n%s\n\n"
                    . '<i>Код справжній, але на цю годину альтанка за цією квартирою не заброньована.</i>',
                htmlspecialchars($account->getPlaceLabel(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            ));

            return;
        }

        $this->answer($bot, sprintf(
            "✅ <b>Все вірно</b>\n\n<b>%s</b>\n%s альтанка · <b>%s–%s</b>\n\n<i>Перевірено о %s.</i>",
            htmlspecialchars(GuardService::place($session), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            SchedulePavilionService::pavilionName($session['pavilion']),
            $session['start']->format('H:i'),
            $session['end']->format('H:i'),
            $now->format('H:i'),
        ));
    }

    private function answer(Nutgram $bot, string $text): void
    {
        $bot->sendMessage(
            text: $text,
            parse_mode: ParseMode::HTML,
            reply_markup: InlineKeyboardMarkup::make()->addRow(InlineKeyboardButton::make(
                '🛡 Хто зараз в альтанці',
                callback_data: GuardCommand::MENU_CALLBACK,
            )),
        );
    }
}
