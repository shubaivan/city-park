<?php

namespace App\Telegram\Guard\Command;

use App\Entity\Account;
use App\Service\GuardService;
use App\Service\SchedulePavilionService;
use App\Service\TelegramUserService;
use App\Telegram\Start\Command\StartCommand;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use SergiX44\Nutgram\Telegram\Types\Internal\InputFile;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

/**
 * «🔒 QR для охорони» — the resident's half of the gate check.
 *
 * Иван's idea, 07.09.2026: «людина з альтанки генерує QR код, охорона зчитує і отримує в
 * боті підтвердження що люди саме в комент часу валідно тут знаходяться». The board tells
 * the guard which flat booked the hour; this tells him that the people in front of him are
 * that flat, without him having to take their word for the number.
 *
 * **The QR is a deep link into the bot**, `t.me/<bot>?start=g-<account>-<signature>`, so
 * the guard needs no scanner app and gets his answer where he already works — the phone
 * camera opens Telegram and the bot replies. Nothing is stored: the question is «is this
 * household in the альтанка right now», and the bookings table already answers it.
 *
 * **The button appears only while a booking is running**, which is also why it needs no
 * explaining: it is on the menu when you are sitting in the pavilion and gone the rest of
 * the month.
 */
class GuardQrCommand
{
    public const MENU_CALLBACK = 'guard-qr';

    public function __construct(
        private GuardService $guard,
        private TelegramUserService $telegramUserService,
    ) {}

    public function __invoke(Nutgram $bot): void
    {
        $user = $this->telegramUserService->getCurrentUser();
        $account = $user ? $this->telegramUserService->resolveAccount($user) : null;
        $now = SchedulePavilionService::createNewDate();
        $session = $account instanceof Account ? $this->guard->runningSessionFor($account, $now) : null;

        if ($session === null) {
            $bot->answerCallbackQuery(
                text: 'QR-код працює лише під час вашого бронювання.',
                show_alert: true,
            );

            return;
        }

        $bot->answerCallbackQuery();

        // Asked of Telegram rather than configured: the dev bot and the prod bot are
        // different usernames, and a link built from the wrong one is a QR that opens a
        // bot the guard is not in.
        $username = $bot->getMe()?->username;

        if ($username === null || $username === '') {
            $bot->sendMessage(text: '⚠️ Не вдалося створити код. Спробуйте ще раз за хвилину.');

            return;
        }

        $link = sprintf('https://t.me/%s?start=%s', $username, $this->guard->mintToken($account));

        $png = self::render($link);

        $bot->sendPhoto(
            photo: InputFile::make($png, 'qr.png'),
            caption: sprintf(
                "🔒 <b>QR для охорони</b>\n\n%s альтанка · <b>%s–%s</b>\n%s\n\n"
                    . "Покажіть цей екран охоронцю — він наведе камеру і бот підтвердить, "
                    . "що альтанка зараз ваша.\n\n"
                    . "<i>Код діє, поки триває бронювання.</i>",
                SchedulePavilionService::pavilionName($session['pavilion']),
                $session['start']->format('H:i'),
                $session['end']->format('H:i'),
                htmlspecialchars(GuardService::place($session), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            ),
            parse_mode: ParseMode::HTML,
            reply_markup: InlineKeyboardMarkup::make()->addRow(StartCommand::homeButton()),
        );
    }

    /**
     * The PNG bytes for one link.
     *
     * Its own method so a test can actually build one: endroid/qr-code 6 dropped the
     * `Builder::create()` fluent entry point that 5 had, and the first shape of this
     * called it — nothing in the suite touched the line, so it reached prod and died
     * there with «Call to undefined method». A picture nobody renders in a test is a
     * picture nobody renders.
     */
    public static function render(string $link): string
    {
        return (new Builder(
            writer: new PngWriter(),
            data: $link,
            size: 600,
            margin: 24,
        ))->build()->getString();
    }
}
