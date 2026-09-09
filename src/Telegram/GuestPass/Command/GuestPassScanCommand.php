<?php

namespace App\Telegram\GuestPass\Command;

use App\Entity\GuestPass;
use App\Entity\QrScan;
use App\Service\GuardService;
use App\Service\GuestPassService;
use App\Service\TelegramUserService;
use App\Telegram\Start\Command\StartCommand;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

/**
 * `/start p-<id>-<signature>` — somebody pointed a camera at a builder's pass.
 *
 * Read by the same people who read a resident's own code: any confirmed resident, and the
 * guard. Here that matters more than anywhere — the stranger with a toolbox in the stairwell
 * is seen by a neighbour, not by the man at the gate.
 *
 * Three answers, and the middle one is the point:
 *
 * - **inside its window** → ✅ who they are, which flat is expecting them, and until when;
 * - **outside it** → ❌ said plainly. This is the whole security model: a pass is on for the
 *   couple of hours or the day its host switched on and never past midnight, so yesterday's
 *   screenshot has to read as *wrong*, not as an error the guard might blame on the bot;
 * - **revoked or unknown** → ❌ the same way, without saying whose it was.
 *
 * Every scan is written to the log — see `QrScan`. It was asked for in the same breath as
 * opening the scan to the house, and it is the only thing in this system that can answer
 * «хто їх пустив і коли вони заходили».
 */
class GuestPassScanCommand
{
    public function __construct(
        private GuestPassService $passes,
        private GuardService $guard,
        private TelegramUserService $telegramUserService,
    ) {}

    public function __invoke(Nutgram $bot, string $payload = ''): void
    {
        $user = $this->telegramUserService->getCurrentUser();
        $viewerAccount = $user ? $this->telegramUserService->resolveAccount($user) : null;

        if (!$this->guard->mayScan($user, $viewerAccount)) {
            $bot->sendMessage(
                text: "🔒 <b>Пропуск мешканця ЖК</b>\n\n"
                    . "Такі коди читають підтверджені мешканці — щоб побачити, кого і в яку "
                    . "квартиру чекають.\n\n"
                    . 'Ви ще не підтверджені: натисніть /phone і поділіться номером телефону.',
                parse_mode: ParseMode::HTML,
                reply_markup: InlineKeyboardMarkup::make()->addRow(StartCommand::homeButton()),
            );

            return;
        }

        $isGuard = $this->guard->isGuard($user);
        $passId = $this->passes->readToken(trim($payload));
        $pass = $passId === null ? null : $this->passes->find($passId);

        if (!$pass instanceof GuestPass || $pass->isRevoked()) {
            $this->passes->recordScan(
                QrScan::KIND_GUEST,
                $pass === null ? QrScan::RESULT_UNKNOWN : QrScan::RESULT_REVOKED,
                $user,
                $pass?->getAccount(),
                $pass,
                $isGuard,
            );

            $this->answer($bot, $pass === null
                ? "⚠️ <b>Код не розпізнано</b>\n\nПопросіть надіслати пропуск ще раз."
                : "❌ <b>Пропуск скасовано</b>\n\n<i>Квартира відкликала цей пропуск — він більше не діє.</i>");

            return;
        }

        $now = new \DateTime('now', new \DateTimeZone('Europe/Kyiv'));

        if (!$pass->isActiveAt($now)) {
            $this->passes->recordScan(
                QrScan::KIND_GUEST,
                QrScan::RESULT_NOT_ACTIVE,
                $user,
                $pass->getAccount(),
                $pass,
                $isGuard,
            );

            $this->answer($bot, sprintf(
                "❌ <b>Пропуск зараз не активний</b>\n\n%s\n%s\n\n"
                    . '<i>Пропуск працює лише у вікні, яке вмикає мешканець. Попросіть '
                    . 'увімкнути його в боті.</i>',
                self::esc($pass->getLabel()),
                self::esc($pass->getAccount()?->getPlaceLabel() ?? ''),
            ));

            return;
        }

        $this->passes->recordScan(
            QrScan::KIND_GUEST,
            QrScan::RESULT_OK,
            $user,
            $pass->getAccount(),
            $pass,
            $isGuard,
        );

        $this->answer($bot, sprintf(
            "✅ <b>Пропуск дійсний</b>\n\n👷 %s\nЧекають у: <b>%s</b>\n\n"
                . "<i>Діє до %s. Перевірено %s.</i>",
            self::esc($pass->getLabel()),
            self::esc($pass->getAccount()?->getPlaceLabel() ?? ''),
            // The date, not only the hour: this answer is looked at as a record — on a
            // screenshot, or a minute after the guard scrolled past another one — and
            // «до 23:59» on its own does not say which day the bot was talking about.
            $pass->getActiveUntil()?->format('d.m о H:i') ?? 'кінця дня',
            $now->format('d.m о H:i'),
        ));
    }

    /**
     * Home, and nothing else.
     *
     * The first version offered «🏛 Альтанки зараз» here, copied from the resident-code
     * answer where a booking is at least what the code is about. Under a builder's pass it
     * is a button about somebody else's pavilion hours, shown to a guard at a gate — an
     * offer that reads as if the bot misunderstood the question.
     */
    private function answer(Nutgram $bot, string $text): void
    {
        $bot->sendMessage(
            text: $text,
            parse_mode: ParseMode::HTML,
            reply_markup: InlineKeyboardMarkup::make()->addRow(StartCommand::homeButton()),
        );
    }

    private static function esc(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
