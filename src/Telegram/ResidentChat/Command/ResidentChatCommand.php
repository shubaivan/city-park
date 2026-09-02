<?php

namespace App\Telegram\ResidentChat\Command;

use App\Service\ResidentChatService;
use App\Service\TelegramUserService;
use App\Telegram\Start\Command\StartCommand;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

/**
 * "🏘 Чат мешканців" — hands out the door, or explains why it is shut.
 *
 * The link is the same one for everybody and is deliberately not a secret: it opens a
 * join request, which the bot then answers (ResidentChatService). What matters is that
 * an unverified resident is told *how* to become verified rather than just refused —
 * unlike the rental noticeboard, where an unlinked reader is a flat-hunter who is not
 * being denied anything. Here they are a neighbour standing outside their own house
 * chat, and the fix is two taps away.
 */
class ResidentChatCommand
{
    public const MENU_CALLBACK = 'resident-chat';

    public function __construct(
        private TelegramUserService $telegramUserService,
        private ResidentChatService $residentChat,
    ) {}

    public function __invoke(Nutgram $bot): void
    {
        $markup = InlineKeyboardMarkup::make();

        if (!$this->residentChat->isConfigured()) {
            $this->respond(
                $bot,
                "🏘 <b>Чат мешканців</b>\n\nЧат ще не відкрито. Скоро запрацює.",
                $markup->addRow(StartCommand::homeButton()),
            );

            return;
        }

        $user = $this->telegramUserService->getCurrentUser();

        if (!$user || !$this->residentChat->mayJoin($user)) {
            $this->respond($bot, $this->howToJoinText(), $markup->addRow(StartCommand::homeButton()));

            return;
        }

        // Someone already inside should not be offered a door they are standing behind.
        // A null answer means Telegram could not be asked — fall back to the invitation,
        // since showing the door to a member is a far smaller mistake than hiding it from
        // somebody who still needs it.
        $inside = $this->residentChat->isMember($bot, $user) === true;

        $markup->addRow(
            InlineKeyboardButton::make(
                $inside ? '🚪 Відкрити чат' : '🚪 Увійти в чат',
                url: $this->residentChat->inviteLink(),
            ),
        );
        $markup->addRow(StartCommand::homeButton());

        $this->respond($bot, $inside ? $this->insideText() : $this->doorText(), $markup);
    }

    private function insideText(): string
    {
        return "🏘 <b>Чат мешканців</b>\n\n"
            . "✅ Ви вже в чаті — повторно заходити не потрібно.\n\n"
            . "Кнопка нижче просто відкриє його.\n\n"
            . '<i>Не бачите чат у списку? Перевірте архів у Telegram — можливо, ви його '
            . 'туди приховали.</i>';
    }

    private function doorText(): string
    {
        return "🏘 <b>Чат мешканців</b>\n\n"
            . "Закритий чат нашого будинку. Зайти може лише той, кого підтверджено "
            . "за особовим рахунком, — сторонніх тут немає.\n\n"
            . "🔒 Ваш номер телефону в чаті не видно нікому: сусіди бачать тільки ім’я, "
            . "яке ви самі поставили в Telegram.\n\n"
            . "<i>Натисніть кнопку нижче — Telegram надішле заявку, бот підтвердить її "
            . "за кілька секунд.</i>";
    }

    private function howToJoinText(): string
    {
        return "🏘 <b>Чат мешканців</b>\n\n"
            . "Це закритий чат будинку — щоб зайти, ваш Telegram має бути прив’язаний "
            . "до особового рахунку ОСББ.\n\n"
            . "Зробити це можна за хвилину:\n"
            . "1️⃣ Натисніть /phone\n"
            . "2️⃣ Поділіться своїм номером телефону\n"
            . "3️⃣ Якщо номер є в реєстрі ОСББ, бот прив’яже вас одразу\n\n"
            . "Після цього поверніться сюди — з’явиться кнопка входу.\n\n"
            . '<i>Живете тут, але номера немає в реєстрі (наприклад, орендуєте квартиру)? '
            . 'Попросіть власника додати вас або зверніться до бухгалтера ОСББ.</i>';
    }

    private function respond(Nutgram $bot, string $text, InlineKeyboardMarkup $markup): void
    {
        if ($bot->isCallbackQuery()) {
            try {
                $bot->editMessageText(text: $text, parse_mode: ParseMode::HTML, reply_markup: $markup);

                return;
            } catch (\Throwable) {
                // A photo card cannot be edited into text — fall through to a new message.
            }
        }

        $bot->sendMessage(text: $text, parse_mode: ParseMode::HTML, reply_markup: $markup);
    }
}
