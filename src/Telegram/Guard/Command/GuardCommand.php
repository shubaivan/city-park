<?php

namespace App\Telegram\Guard\Command;

use App\Entity\Account;
use App\Service\GuardService;
use App\Service\SchedulePavilionService;
use App\Service\TelegramUserService;
use App\Telegram\Start\Command\StartCommand;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

/**
 * The pavilion board — read by two people asking two different questions.
 *
 * **The guard** («🛡 Хто зараз в альтанці») walks up to whoever is sitting there and asks
 * who they are. Everything this screen does is let him finish that sentence: it names the
 * flat that booked the hour he is standing in, and then the rest of tonight so he is not
 * surprised at 22:00.
 *
 * **A resident** («🏛 Альтанки зараз») is asking «вільно чи ні, і коли звільниться» before
 * walking down with a kettle. That question is answered by the hours and the pavilion; the
 * flat number adds nothing to it. So the board renders **without flat numbers for
 * everybody but the guard** — publishing to 457 people that a named household is out of
 * its flat between 18:00 and 21:00, on a screen with a refresh button, is a different
 * feature from the one anybody asked for. Their own booking is still marked «📌 це ви»,
 * the same way the debtors' board marks the reader's own line.
 *
 * `board()` takes that as a **required** argument rather than a defaulted one: this is
 * exactly the switch whose permissive default would leak while looking like the feature
 * working, which is the same reason an empty GUARD_TELEGRAM_IDS means nobody.
 *
 * Still not on the slash menu: `/guard` is registered as a handler but deliberately left
 * out of `BotMenuUpdateCommand::MENU`.
 */
class GuardCommand
{
    public const MENU_CALLBACK = 'guard-board';

    public function __construct(
        private GuardService $guard,
        private TelegramUserService $telegramUserService,
    ) {}

    public function __invoke(Nutgram $bot): void
    {
        $user = $this->telegramUserService->getCurrentUser();
        $isGuard = $this->guard->isGuard($user);
        $viewer = $user ? $this->telegramUserService->resolveAccount($user) : null;

        // House-internal, like the debtors' board and the complaints register: it says
        // what is happening in the ЖК's own yard right now. Somebody who opened the bot
        // through 🔑 Оренда to browse flats is not part of the house. A guard is let in
        // whether or not he has an особовий рахунок — he is staff, not a resident.
        if (!$isGuard && !$viewer instanceof Account) {
            // Same shape as every other refusal in this bot: say so, do not go quiet. A
            // silent button is indistinguishable from a broken one.
            $text = 'Цей розділ — для мешканців будинку. Щоб підтвердити себе, '
                . 'надішліть свій номер телефону: /phone';

            if ($bot->isCallbackQuery()) {
                $bot->answerCallbackQuery(text: $text, show_alert: true);

                return;
            }

            $bot->sendMessage(text: '🏛 ' . $text);

            return;
        }

        $now = SchedulePavilionService::createNewDate();
        $text = $this->board($now, namesFlats: $isGuard, viewer: $viewer);

        $markup = InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('🔄 Оновити', callback_data: self::MENU_CALLBACK))
            ->addRow(StartCommand::homeButton());

        if ($bot->isCallbackQuery()) {
            $bot->answerCallbackQuery();

            // Refreshing a board that has not changed is Telegram's "message is not
            // modified" error, which would surface as a red toast on a button whose whole
            // job is to be pressed repeatedly through a quiet evening.
            try {
                $bot->editMessageText(text: $text, parse_mode: ParseMode::HTML, reply_markup: $markup);
            } catch (\Throwable) {
                $bot->answerCallbackQuery(text: 'Без змін.');
            }

            return;
        }

        $bot->sendMessage(text: $text, parse_mode: ParseMode::HTML, reply_markup: $markup);
    }

    /**
     * The board itself: what is running now, then the rest of today.
     *
     * Kept free of Nutgram so it can be read in a test — the ordering and the wording are
     * the whole feature, and they are what a guard reads in the dark on a phone.
     */
    public function board(\DateTimeInterface $now, bool $namesFlats, ?Account $viewer = null): string
    {
        $sessions = $this->guard->sessionsOfDay($now);

        $running = [];
        $later = [];

        foreach ($sessions as $session) {
            if (GuardService::isRunning($session, $now)) {
                $running[] = $session;

                continue;
            }

            if (!GuardService::isOver($session, $now)) {
                $later[] = $session;
            }
        }

        $lines = [
            sprintf('%s <b>Альтанки — %s</b>', $namesFlats ? '🛡' : '🏛', $this->day($now)),
            sprintf('<i>Станом на %s</i>', $now->format('H:i')),
            '',
        ];

        if ($running === []) {
            $lines[] = '✅ <b>Зараз альтанки вільні</b> — броні на цю годину немає.';
        } else {
            $lines[] = '🔴 <b>Зараз</b>';

            foreach ($running as $session) {
                $lines[] = $this->line($session, $namesFlats, $viewer);
            }
        }

        if ($later !== []) {
            $lines[] = '';
            $lines[] = '⏭ <b>Далі сьогодні</b>';

            foreach ($later as $session) {
                $lines[] = $this->line($session, $namesFlats, $viewer);
            }
        }

        if ($running === [] && $later === []) {
            $lines[] = '';
            $lines[] = '<i>На сьогодні бронювань більше немає.</i>';
        }

        $lines[] = '';
        // Two audiences, two next actions. The guard's line is an instruction for the
        // case the board exists to catch; a resident reading it would be told to go
        // interrogate their neighbours.
        $lines[] = $namesFlats
            ? '<i>Якщо в альтанці хтось є, а тут його немає — запитайте номер квартири '
                . 'і передайте в ОСББ.</i>'
            : '<i>Вільну годину можна зайняти кнопкою «Бронювання».</i>';

        return implode("\n", $lines);
    }

    /**
     * One session.
     *
     * The flat is printed only for the guard — for everybody else the line says «зайнято»,
     * which is the entire answer to the question they opened this with. The exception is
     * the reader's own booking: «📌 це ви» tells them at a glance which of three
     * near-identical lines is theirs, exactly as the debtors' board marks their own row.
     * That is their own information, not a neighbour's.
     *
     * @param array{pavilion:int, start:\DateTimeImmutable, end:\DateTimeImmutable, account:?Account, user:\App\Entity\TelegramUser} $session
     */
    private function line(array $session, bool $namesFlats, ?Account $viewer = null): string
    {
        $own = $viewer instanceof Account
            && $session['account'] instanceof Account
            && $this->sameHousehold($session['account'], $viewer);

        if ($namesFlats) {
            $who = GuardService::place($session);
        } elseif ($own) {
            $who = '📌 це ви';
        } else {
            $who = 'зайнято';
        }

        return sprintf(
            '• <b>%s–%s</b> · %s альтанка · <b>%s</b>',
            $session['start']->format('H:i'),
            $session['end']->format('H:i'),
            SchedulePavilionService::pavilionName($session['pavilion']),
            htmlspecialchars($who, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        );
    }

    /**
     * «Це ви» must cover the whole household, not one особовий рахунок.
     *
     * A flat and a parking space are two Accounts tied by `owner_group_id`, and booking
     * limits already count across the group — so a booking made from the flat must read as
     * yours when you open the board from the parking space. Matched on an **explicit**
     * group id, never a bare one, so an ungrouped account whose id happens to equal another
     * household's group number is not marked as theirs. Same rule as
     * DebtBoardService::isViewer().
     */
    private function sameHousehold(Account $booked, Account $viewer): bool
    {
        if ($booked->getId() === $viewer->getId()) {
            return true;
        }

        $a = $booked->getOwnerGroupId();
        $b = $viewer->getOwnerGroupId();

        return $a !== null && $b !== null && $a === $b;
    }

    private function day(\DateTimeInterface $now): string
    {
        $months = [
            1 => 'січня', 'лютого', 'березня', 'квітня', 'травня', 'червня',
            'липня', 'серпня', 'вересня', 'жовтня', 'листопада', 'грудня',
        ];

        return sprintf('%d %s', (int)$now->format('j'), $months[(int)$now->format('n')]);
    }
}
