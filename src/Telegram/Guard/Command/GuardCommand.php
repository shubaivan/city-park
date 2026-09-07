<?php

namespace App\Telegram\Guard\Command;

use App\Service\GuardService;
use App\Service\SchedulePavilionService;
use App\Service\TelegramUserService;
use App\Telegram\Start\Command\StartCommand;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

/**
 * «🛡 Хто зараз в альтанці» — the gate's half of the booking register.
 *
 * The guard walks up to whoever is in the pavilion and asks who they are. Everything this
 * screen does is let him finish that sentence: it names the flat that booked the hour he
 * is standing in, and then the rest of tonight so he is not surprised at 22:00.
 *
 * Not on the slash menu and not in anybody else's inline menu: `/guard` is registered as a
 * handler but deliberately left out of `BotMenuUpdateCommand::MENU`, which is pushed to
 * all 457 private chats. The button appears on the main menu for a guard and for nobody
 * else.
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

        if (!$this->guard->isGuard($user)) {
            // Same shape as every other refusal in this bot: say so, do not go quiet. A
            // silent button is indistinguishable from a broken one.
            if ($bot->isCallbackQuery()) {
                $bot->answerCallbackQuery(text: 'Цей розділ — для охорони.', show_alert: true);

                return;
            }

            $bot->sendMessage(text: '🛡 Цей розділ — для охорони ЖК.');

            return;
        }

        $now = SchedulePavilionService::createNewDate();
        $text = $this->board($now);

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
    public function board(\DateTimeInterface $now): string
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
            sprintf('🛡 <b>Альтанки — %s</b>', $this->day($now)),
            sprintf('<i>Станом на %s</i>', $now->format('H:i')),
            '',
        ];

        if ($running === []) {
            $lines[] = '✅ <b>Зараз альтанки вільні</b> — броні на цю годину немає.';
        } else {
            $lines[] = '🔴 <b>Зараз</b>';

            foreach ($running as $session) {
                $lines[] = $this->line($session);
            }
        }

        if ($later !== []) {
            $lines[] = '';
            $lines[] = '⏭ <b>Далі сьогодні</b>';

            foreach ($later as $session) {
                $lines[] = $this->line($session);
            }
        }

        if ($running === [] && $later === []) {
            $lines[] = '';
            $lines[] = '<i>На сьогодні бронювань більше немає.</i>';
        }

        $lines[] = '';
        $lines[] = '<i>Якщо в альтанці хтось є, а тут його немає — запитайте номер квартири '
            . 'і передайте в ОСББ.</i>';

        return implode("\n", $lines);
    }

    /** @param array{pavilion:int, start:\DateTimeImmutable, end:\DateTimeImmutable, account:?\App\Entity\Account, user:\App\Entity\TelegramUser} $session */
    private function line(array $session): string
    {
        return sprintf(
            '• <b>%s–%s</b> · %s альтанка · <b>%s</b>',
            $session['start']->format('H:i'),
            $session['end']->format('H:i'),
            SchedulePavilionService::pavilionName($session['pavilion']),
            htmlspecialchars(GuardService::place($session), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        );
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
