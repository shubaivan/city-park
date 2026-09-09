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
 * **It is a resident's pass, not a booking ticket** (Иван, 09.09.2026). It began as the
 * second: the button appeared only while a booking was running and was gone the rest of the
 * month, which needed no explaining but also meant almost nobody ever saw it exist. Now
 * every confirmed resident whose access is not blocked has it on the menu, and the scan
 * says two things — that the holder is one of us, and whether the альтанка is theirs right
 * now. `GuardService::mayHoldQr()` is the one definition of who gets one.
 *
 * **A block does not take it away** — not a debt, not a missed pavilion photo, not an
 * admin's hand, not a vote of the house. Every one of those decides whether somebody may
 * *book the альтанка*; none of them decides whether they live here, and that is all this
 * code says. Withholding it would turn the pass into a public statement that its holder
 * owes money, made to whichever neighbour scanned them.
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

        if (!$this->guard->mayHoldQr($account)) {
            // Marked and explained rather than silently missing: the button is drawn only
            // for somebody who may have a code, so anyone who reaches this has arrived from
            // an older keyboard, and «нічого не сталося» is the worst possible answer.
            $bot->answerCallbackQuery(
                text: 'Спершу підтвердіть номер телефону: /phone',
                show_alert: true,
            );

            return;
        }

        $session = $this->guard->runningSessionFor($account, $now);

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

        $photo = self::photo($link);

        if ($photo === null) {
            $bot->sendMessage(text: '⚠️ Не вдалося створити код. Спробуйте ще раз за хвилину.');

            return;
        }

        // The booking line is the half that changes; the pass itself does not. Printed only
        // when there is one, because «броні зараз немає» on your own pass is noise: you
        // know, you did not book anything.
        $booking = $session === null ? '' : sprintf(
            "🏛 Зараз ваша бронь: %s альтанка · <b>%s–%s</b>\n",
            SchedulePavilionService::pavilionName($session['pavilion']),
            $session['start']->format('H:i'),
            $session['end']->format('H:i'),
        );

        $bot->sendPhoto(
            photo: $photo,
            caption: sprintf(
                "🪪 <b>QR-код мешканця</b>\n\n%s\n%s\n"
                    . "Покажіть цей екран охоронцю або сусідові — камера відкриє бота, "
                    . "і він підтвердить, що ви мешканець ЖК, назве вашу квартиру та "
                    . "покаже, чи є у вас зараз бронь на альтанку.\n\n"
                    . "<i>Код ваш постійний. Показувати його стороннім не варто.</i>",
                htmlspecialchars($account->getPlaceLabel(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                $booking,
            ),
            parse_mode: ParseMode::HTML,
            reply_markup: InlineKeyboardMarkup::make()->addRow(StartCommand::homeButton()),
        );
    }

    /**
     * The QR as Telegram wants it: a **stream**, not the bytes.
     *
     * `InputFile` wraps a resource or a path and answers «Invalid resource specified» to a
     * raw string — which is a 500 on /hook, after which Telegram retries the same tap four
     * times until the callback query is too old to answer. From the outside that is
     * «натиснув і нічого» (07.09.2026), with the real cause four lines down in the log
     * under two rounds of retries.
     */
    public static function photo(string $link): ?InputFile
    {
        $stream = fopen('php://temp', 'r+');

        if ($stream === false) {
            return null;
        }

        fwrite($stream, self::render($link));
        rewind($stream);

        return InputFile::make($stream, 'qr.png');
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
