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
 * The pavilion board: who is in the альтанка now, and who is booked for the rest of today.
 *
 * **One board, and every confirmed resident reads it — flats included** (Иван, 09.09.2026).
 * It shipped with two versions: the guard's named the flat, everybody else's said
 * «зайнято», on the reasoning that publishing «this household is out between 18:00 and
 * 21:00» to 457 people was a different feature from the one asked for. Two things undid
 * that. The scan of a resident's QR already names the flat to whoever reads it, so the
 * house was being shown the same fact through one door and refused it through another; and
 * a booking means the household is *twenty metres away in the yard*, not out for the
 * evening, which is what made the original worry weak. The debtors' board publishes flat
 * and sum to the same readers every month.
 *
 * What it is *not* open to is an unlinked visitor — this says what is happening in the
 * ЖК's own yard, and somebody who opened the bot through 🔑 Оренда to browse flats is not
 * part of the house. A guard is admitted whether or not he has an особовий рахунок: he is
 * staff, not a resident.
 *
 * The reader's own booking is still marked «📌 це ви» — three near-identical lines and
 * «ви треті» underneath is a puzzle, the same one the debtors' board's podium had.
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
        $text = $this->board($now, viewer: $viewer);

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
    public function board(\DateTimeInterface $now, ?Account $viewer = null): string
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
            sprintf('🏛 <b>Альтанки — %s</b>', $this->day($now)),
            sprintf('<i>Станом на %s</i>', $now->format('H:i')),
            '',
        ];

        if ($running === []) {
            $lines[] = '✅ <b>Зараз альтанки вільні</b> — броні на цю годину немає.';
        } else {
            $lines[] = '🔴 <b>Зараз</b>';

            foreach ($running as $session) {
                $lines[] = $this->line($session, $viewer);
            }
        }

        if ($later !== []) {
            $lines[] = '';
            $lines[] = '⏭ <b>Далі сьогодні</b>';

            foreach ($later as $session) {
                $lines[] = $this->line($session, $viewer);
            }
        }

        if ($running === [] && $later === []) {
            $lines[] = '';
            $lines[] = '<i>На сьогодні бронювань більше немає.</i>';
        }

        $lines[] = '';
        // One line for everybody, and it is the resident's: «go and interrogate your
        // neighbours» is an instruction for staff, and the board is now read by the house.
        // The guard's own next action needs no printing — checking who is there is the
        // whole of his job.
        $lines[] = '<i>Вільну годину можна зайняти кнопкою «Бронювання».</i>';

        return implode("\n", $lines);
    }

    /**
     * One session: the hours, the pavilion and the flat that booked it.
     *
     * The reader's own line says so as well — «буд. 19, кв. 85 · 📌 це ви» — because
     * finding your own booking among three near-identical lines is otherwise a small
     * puzzle, and the mark is matched across the whole owner group: a flat and its
     * паркомісце are one household.
     *
     * @param array{pavilion:int, start:\DateTimeImmutable, end:\DateTimeImmutable, account:?Account, user:\App\Entity\TelegramUser} $session
     */
    private function line(array $session, ?Account $viewer = null): string
    {
        $own = $viewer instanceof Account
            && $session['account'] instanceof Account
            && $this->sameHousehold($session['account'], $viewer);

        $who = GuardService::place($session) . ($own ? ' · 📌 це ви' : '');

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
