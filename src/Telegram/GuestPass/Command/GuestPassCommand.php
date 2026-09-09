<?php

namespace App\Telegram\GuestPass\Command;

use App\Entity\Account;
use App\Entity\GuestPass;
use App\Service\GuestPassService;
use App\Service\TelegramUserService;
use App\Telegram\Guard\Command\GuardQrCommand;
use App\Telegram\Start\Command\StartCommand;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

/**
 * «👷 Пропуски» — the flat's passes for the people working in it.
 *
 * The list is short by construction (three at most), so it is a list of buttons and one
 * card behind each: the card is where the picture, the day and the two decisions live.
 *
 * **The picture is minted once and forwarded once.** Re-sending a new QR to the бригадир
 * every morning is the friction that would kill this feature, so the code is permanent and
 * the *day* is what the resident switches on. A guard scanning it on a day nobody switched
 * on is told exactly that.
 */
class GuestPassCommand
{
    public const MENU_CALLBACK = 'pass:list';
    public const CARD_PREFIX = 'pass:view:';
    /** «🔁 До кінця дня». The hour windows carry their length: pass:hrs:<id>:<hours>. */
    public const ACTIVATE_PREFIX = 'pass:on:';
    public const HOURS_PREFIX = 'pass:hrs:';
    public const OFF_PREFIX = 'pass:off:';
    public const REVOKE_PREFIX = 'pass:del:';
    public const REVOKE_OK_PREFIX = 'pass:delok:';
    public const SHARE_PREFIX = 'pass:share:';

    public function __construct(
        private GuestPassService $passes,
        private TelegramUserService $telegramUserService,
    ) {}

    public function __invoke(Nutgram $bot): void
    {
        $data = $bot->isCallbackQuery() ? (string)($bot->callbackQuery()->data ?? '') : '';
        $account = $this->account();

        if (!$account instanceof Account) {
            $bot->isCallbackQuery() && $bot->answerCallbackQuery();
            $bot->sendMessage(
                text: "👷 <b>Пропуски для робітників</b>\n\n"
                    . "Щоб видавати пропуски, бот має знати вашу квартиру.\n"
                    . 'Натисніть /phone і поділіться номером телефону.',
                parse_mode: ParseMode::HTML,
                reply_markup: InlineKeyboardMarkup::make()->addRow(StartCommand::homeButton()),
            );

            return;
        }

        if (str_starts_with($data, self::CARD_PREFIX)) {
            $this->openCard($bot, $account, (int)substr($data, strlen(self::CARD_PREFIX)));

            return;
        }

        if (str_starts_with($data, self::ACTIVATE_PREFIX)) {
            $this->activate($bot, $account, (int)substr($data, strlen(self::ACTIVATE_PREFIX)));

            return;
        }

        if (str_starts_with($data, self::HOURS_PREFIX)) {
            [$id, $hours] = array_pad(explode(':', substr($data, strlen(self::HOURS_PREFIX))), 2, '0');
            $this->activate($bot, $account, (int)$id, (int)$hours);

            return;
        }

        if (str_starts_with($data, self::OFF_PREFIX)) {
            $this->deactivate($bot, $account, (int)substr($data, strlen(self::OFF_PREFIX)));

            return;
        }

        if (str_starts_with($data, self::REVOKE_PREFIX)) {
            $this->askRevoke($bot, $account, (int)substr($data, strlen(self::REVOKE_PREFIX)));

            return;
        }

        if (str_starts_with($data, self::REVOKE_OK_PREFIX)) {
            $this->revoke($bot, $account, (int)substr($data, strlen(self::REVOKE_OK_PREFIX)));

            return;
        }

        if (str_starts_with($data, self::SHARE_PREFIX)) {
            $this->share($bot, $account, (int)substr($data, strlen(self::SHARE_PREFIX)));

            return;
        }

        $this->list($bot, $account);
    }

    public function list(Nutgram $bot, Account $account): void
    {
        $bot->isCallbackQuery() && $bot->answerCallbackQuery();

        $passes = $this->passes->liveFor($account);
        $markup = InlineKeyboardMarkup::make();

        foreach ($passes as $pass) {
            $markup->addRow(InlineKeyboardButton::make(
                self::buttonLabel($pass),
                callback_data: self::CARD_PREFIX . $pass->getId(),
            ));
        }

        if ($this->passes->mayCreate($account)) {
            $markup->addRow(InlineKeyboardButton::make('➕ Новий пропуск', callback_data: GuestPassCreate::START_CALLBACK));
        }

        $markup->addRow(StartCommand::homeButton());

        $bot->sendMessage(
            text: "👷 <b>Пропуски для робітників</b>\n\n"
                . "Зробіть пропуск для бригади, майстра чи доставки й перешліть їм картинку.\n"
                . "Охоронець або будь-який мешканець наводить камеру — і бачить, що цих людей "
                . "чекають саме у вашій квартирі.\n\n"
                . ($passes === []
                    ? '<i>Поки що жодного пропуску.</i>'
                    : '<i>Пропуск вмикається на 2 чи 4 години або до кінця дня — і ніколи '
                        . 'довше. Картинку пересилати заново не треба: вмикайте той самий '
                        . 'пропуск щоразу, коли ці люди приходять.</i>'),
            parse_mode: ParseMode::HTML,
            reply_markup: $markup,
        );
    }

    /**
     * «🟢 09.09 · Бригада, ремонт · до 14:20», or «⚪️ 09.09 · Бригада, ремонт» when the
     * window is shut.
     *
     * The date is the day the pass was **issued**, and it leads the label the way every
     * other list of published things in this bot does: a flat with three passes reads them
     * as «that one is from today, that one is from the ремонт in June», and dates at a
     * fixed width line up down the column where a trailing one cannot. The hour at the end
     * is the live half — Telegram truncates from the right, and a pass that is off has no
     * hour to lose.
     */
    private static function buttonLabel(GuestPass $pass): string
    {
        $now = new \DateTime('now', new \DateTimeZone('Europe/Kyiv'));
        $issued = $pass->getCreatedAt()->format('d.m');

        return $pass->isActiveAt($now)
            ? sprintf('🟢 %s · %s · до %s', $issued, $pass->getLabel(), $pass->getActiveUntil()?->format('H:i'))
            : sprintf('⚪️ %s · %s', $issued, $pass->getLabel());
    }

    private function openCard(Nutgram $bot, Account $account, int $id): void
    {
        $bot->isCallbackQuery() && $bot->answerCallbackQuery();

        $pass = $this->own($account, $id);

        if ($pass === null) {
            $bot->sendMessage(text: '⚠️ Цей пропуск уже скасовано.');
            $this->list($bot, $account);

            return;
        }

        $this->sendCard($bot, $pass);
    }

    /** The card: the QR itself, what it says today, and the two decisions. */
    public function sendCard(Nutgram $bot, GuestPass $pass, bool $created = false): void
    {
        $link = $this->link($bot, $pass);

        if ($link === null) {
            $bot->sendMessage(text: '⚠️ Не вдалося створити код. Спробуйте ще раз за хвилину.');

            return;
        }

        $photo = GuardQrCommand::photo($link);

        if ($photo === null) {
            $bot->sendMessage(text: '⚠️ Не вдалося створити код. Спробуйте ще раз за хвилину.');

            return;
        }

        $now = new \DateTime('now', new \DateTimeZone('Europe/Kyiv'));
        $active = $pass->isActiveAt($now);

        $markup = InlineKeyboardMarkup::make();

        // The two shapes a visit actually takes: «приїхали на пару годин» and «працюють
        // весь день». Both windows are offered whether or not one is already running — a
        // delivery that was let in for the day is exactly the case for shortening it.
        $markup->addRow(
            InlineKeyboardButton::make('⏱ 2 години', callback_data: self::HOURS_PREFIX . $pass->getId() . ':2'),
            InlineKeyboardButton::make('⏱ 4 години', callback_data: self::HOURS_PREFIX . $pass->getId() . ':4'),
        );
        $markup->addRow(InlineKeyboardButton::make(
            $active ? '🔁 Продовжити до кінця дня' : '🔁 Активувати до кінця дня',
            callback_data: self::ACTIVATE_PREFIX . $pass->getId(),
        ));

        if ($active) {
            // The delivery came and went at 11:20; leaving the window open until midnight
            // is the thing this feature exists to avoid.
            $markup->addRow(InlineKeyboardButton::make(
                '⏹ Вимкнути зараз',
                callback_data: self::OFF_PREFIX . $pass->getId(),
            ));
        }

        $markup
            ->addRow(InlineKeyboardButton::make('📤 Переслати у Viber / SMS', callback_data: self::SHARE_PREFIX . $pass->getId()))
            ->addRow(InlineKeyboardButton::make('🗑 Скасувати пропуск', callback_data: self::REVOKE_PREFIX . $pass->getId()))
            ->addRow(
                InlineKeyboardButton::make('👷 Усі пропуски', callback_data: self::MENU_CALLBACK),
                StartCommand::homeButton(),
            );

        $bot->sendPhoto(
            photo: $photo,
            caption: sprintf(
                "👷 <b>%s</b>\n%s\n\n%s\n%s\n"
                    . "Перешліть цю картинку тим, кого чекаєте. Охоронець або мешканець "
                    . "наведе камеру — і побачить, що їх чекають у вашій квартирі.\n\n"
                    . '<i>Пропуск працює лише у вікні, яке ви увімкнули, і ніколи довше '
                    . 'ніж до кінця дня. Наступного разу просто увімкніть його знову — та '
                    . 'сама картинка, пересилати заново не треба.</i>',
                self::esc($pass->getLabel()),
                self::esc($pass->getAccount()?->getPlaceLabel() ?? '')
                    . ' · виданий ' . $pass->getCreatedAt()->format('d.m.Y'),
                $active
                    ? sprintf('✅ <b>Діє до %s</b>', $pass->getActiveUntil()?->format('d.m о H:i'))
                    : '⚪️ <b>Зараз не активний</b> — охорона не пропустить',
                $this->history($pass),
            ),
            parse_mode: ParseMode::HTML,
            reply_markup: $markup,
        );

        if ($created) {
            // Said once, when it matters: the picture has just been made and the next thing
            // the person does is decide who to send it to.
            $bot->sendMessage(
                text: '<i>Не публікуйте цей код у відкритих чатах — у відповіді видно вашу квартиру.</i>',
                parse_mode: ParseMode::HTML,
            );
        }
    }

    /**
     * When this pass was last checked at the gate — the flat's own «коли прийшли робітники».
     *
     * The host is told who checked only as «охорона» or «мешканець»: naming the neighbour
     * who scanned would turn a security tool into something people avoid using, and the
     * time is the whole of what the flat wanted to know.
     */
    private function history(GuestPass $pass): string
    {
        $scans = $this->passes->scansOf($pass, 3);

        if ($scans === []) {
            return "\n<i>Ще жодного разу не перевіряли.</i>\n";
        }

        $lines = array_map(
            static fn ($scan): string => sprintf(
                '• %s — %s',
                $scan->getCreatedAt()->format('d.m H:i'),
                $scan->isByGuard() ? 'охорона' : 'мешканець',
            ),
            $scans,
        );

        return sprintf(
            "\n🕘 <b>Перевіряли</b> (усього %d):\n%s\n",
            $pass->getScans(),
            implode("\n", $lines),
        );
    }

    private function activate(Nutgram $bot, Account $account, int $id, ?int $hours = null): void
    {
        $pass = $this->own($account, $id);

        if ($pass === null) {
            $bot->answerCallbackQuery(text: 'Цей пропуск уже скасовано.', show_alert: true);

            return;
        }

        $this->passes->activate($pass, $hours);
        $bot->answerCallbackQuery(text: sprintf(
            'Пропуск діє до %s.',
            $pass->getActiveUntil()?->format('d.m о H:i') ?? 'кінця дня',
        ));

        $this->sendCard($bot, $pass);
    }

    private function deactivate(Nutgram $bot, Account $account, int $id): void
    {
        $pass = $this->own($account, $id);

        if ($pass === null) {
            $bot->answerCallbackQuery(text: 'Цей пропуск уже скасовано.', show_alert: true);

            return;
        }

        $this->passes->deactivate($pass);
        $bot->answerCallbackQuery(text: 'Пропуск вимкнено.');

        $this->sendCard($bot, $pass);
    }

    private function askRevoke(Nutgram $bot, Account $account, int $id): void
    {
        $bot->answerCallbackQuery();

        $pass = $this->own($account, $id);

        if ($pass === null) {
            $bot->sendMessage(text: '⚠️ Цей пропуск уже скасовано.');

            return;
        }

        $bot->sendMessage(
            text: sprintf(
                "🗑 Скасувати пропуск <b>%s</b>?\n\n"
                    . '<i>Після цього код перестане працювати назавжди — навіть якщо '
                    . 'картинка залишилась у когось у телефоні.</i>',
                self::esc($pass->getLabel()),
            ),
            parse_mode: ParseMode::HTML,
            reply_markup: InlineKeyboardMarkup::make()->addRow(
                InlineKeyboardButton::make('🗑 Так, скасувати', callback_data: self::REVOKE_OK_PREFIX . $pass->getId()),
                InlineKeyboardButton::make('⬅️ Ні', callback_data: self::CARD_PREFIX . $pass->getId()),
            ),
        );
    }

    private function revoke(Nutgram $bot, Account $account, int $id): void
    {
        $pass = $this->own($account, $id);

        if ($pass !== null) {
            $this->passes->revoke($pass);
        }

        $bot->answerCallbackQuery(text: 'Пропуск скасовано.');
        $this->list($bot, $account);
    }

    /**
     * The forwardable copy: the QR picture with a plain-text block under it.
     *
     * What the бригадир does with this is hold up his phone at the gate, so the picture is
     * the part that has to survive the trip — a link he can paste into Viber is a link
     * nobody can scan. The text carries the same link spelled out for a guard who is in
     * Telegram, and no markup at all: somebody selects it with a thumb and drops it into
     * another app.
     */
    private function share(Nutgram $bot, Account $account, int $id): void
    {
        $bot->answerCallbackQuery();

        $pass = $this->own($account, $id);
        $link = $pass === null ? null : $this->link($bot, $pass);

        if ($pass === null || $link === null) {
            $bot->sendMessage(text: '⚠️ Цей пропуск уже скасовано.');

            return;
        }

        $photo = GuardQrCommand::photo($link);

        if ($photo === null) {
            $bot->sendMessage(text: '⚠️ Не вдалося створити код. Спробуйте ще раз за хвилину.');

            return;
        }

        // **The picture is the pass; the link is only the shortcut.** This block was text
        // and a url, on the reading that a бригадир outside Telegram needs something he
        // can paste — but what he does with it is stand at the gate and hold up his phone,
        // and a guard cannot scan a link. Sent as the QR with the text under it, one
        // message serves both: forwarded inside Telegram it arrives whole, and saved into
        // Viber it is a picture with a caption to copy.
        //
        // Two halves in the text: a line for the host explaining what this is, then the
        // block itself, written to the person who will be standing at the gate holding it.
        // An older version said «Покажіть охороні цей код: <url>» — and a бригадир who
        // taps his own link is told «код читають підтверджені мешканці», which reads as a
        // pass that does not work. It has to say who opens what.
        $bot->sendPhoto(
            photo: $photo,
            caption: sprintf(
                "Перешліть цю картинку тим, кого чекаєте — разом із текстом під нею.\n\n"
                    . "———\n"
                    . "Пропуск у ЖК «City Park»\n%s\n%s\n"
                    . "Дійсний: %s\n\n"
                    . "Покажіть цю картинку охороні на вході — охоронець наведе на неї "
                    . "камеру. Якщо він у Telegram, те саме відкриває посилання:\n%s",
                $pass->getLabel(),
                $pass->getAccount()?->getPlaceLabel() ?? '',
                $pass->isActiveAt(new \DateTime('now', new \DateTimeZone('Europe/Kyiv')))
                    ? 'сьогодні до ' . $pass->getActiveUntil()?->format('H:i')
                    : 'мешканець вмикає його на час вашого приходу',
                $link,
            ),
        );
    }

    /** The pass, if it belongs to this flat and is still live. */
    private function own(Account $account, int $id): ?GuestPass
    {
        $pass = $this->passes->find($id);

        return $pass !== null
            && !$pass->isRevoked()
            && $pass->getAccount()?->getId() === $account->getId()
                ? $pass
                : null;
    }

    private function link(Nutgram $bot, GuestPass $pass): ?string
    {
        // Asked of Telegram rather than configured: the dev bot and the prod bot are
        // different usernames, and a link built from the wrong one opens a bot the guard is
        // not in.
        try {
            $username = $bot->getMe()?->username;
        } catch (\Throwable) {
            $username = null;
        }

        return $username === null || $username === ''
            ? null
            : sprintf('https://t.me/%s?start=%s', $username, $this->passes->mintToken($pass));
    }

    private function account(): ?Account
    {
        $user = $this->telegramUserService->getCurrentUser();

        return $user ? $this->telegramUserService->resolveAccount($user) : null;
    }

    private static function esc(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
