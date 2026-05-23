<?php

namespace App\Telegram\Info\Command;

use App\Telegram\Start\Command\StartCommand;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

class InfoCommand
{
    private const MENU_CALLBACK = 'info-menu';

    /**
     * @var array<string, array{title:string, body:string}>
     */
    private const TOPICS = [
        'book' => [
            'title' => '📅 Як забронювати?',
            'body' => "<b>📅 Як забронювати альтанку</b>\n\n"
                . "1️⃣ Натисніть <b>«Бронювання»</b> у головному меню\n"
                . "2️⃣ Оберіть альтанку (Перша або Друга)\n"
                . "3️⃣ Оберіть місяць → день → час\n"
                . "4️⃣ Підтвердіть\n\n"
                . "Бронюйте лише ті години, які реально будете використовувати — це коректно по відношенню до сусідів.",
        ],
        'limits' => [
            'title' => '⏱ Які обмеження?',
            'body' => "<b>⏱ Обмеження бронювання</b>\n\n"
                . "• Не більше <b>4 годин на день</b> від одного аккаунта\n"
                . "• Не більше <b>20 годин на місяць</b> від одного аккаунта\n"
                . "• Не можна бронювати одну і ту саму годину в обох альтанках одночасно\n"
                . "• Не можна залишати «одну годину» між двома існуючими бронюваннями (правило проти штучних дірок)\n"
                . "• <b>Альт. 1</b>: можна з 9:00 до 23:00\n"
                . "• <b>Альт. 2</b>: можна з 21:00 до 8:00 (нічна)",
        ],
        'eligibility' => [
            'title' => '🏘 Хто може бронювати?',
            'body' => "<b>🏘 Право на бронювання альтанки</b>\n\n"
                . "Бронювання доступне <b>лише власникам квартир</b>.\n\n"
                . "<b>НЕ можуть бронювати:</b>\n"
                . "🚗 Власники паркомісць\n"
                . "📦 Власники кладових\n\n"
                . "<b>Чому?</b>\n"
                . "Паркомісця та кладові не сплачують внесок на утримання двору, тож альтанки для них наразі недоступні.\n\n"
                . "Якщо у вас є й квартира, і паркомісце/кладова — бронюйте з аккаунта квартири.",
        ],
        'debt' => [
            'title' => '💸 Борг і блокування',
            'body' => "<b>💸 Борг та блокування</b>\n\n"
                . "• Якщо ваш борг перевищує <b>1300 грн</b>, бронювання тимчасово блокується\n"
                . "• Після сплати — доступ відновлюється автоматично\n"
                . "• Питання по аккаунту — Аліна Бухгалтер, +380 93 658 32 02\n"
                . "• Технічні питання з ботом — Іван, +380 63 302 26 66",
        ],
        'photo' => [
            'title' => '📸 Фото після бронювання',
            'body' => "<b>📸 Фото після використання альтанки</b>\n\n"
                . "Після останньої години ваших послідовних бронювань потрібно надіслати <b>одне фото</b> зовнішнього вигляду альтанки.\n\n"
                . "<b>Приклад:</b> якщо ви забронювали 19:00, 20:00 і 21:00 — це одна сесія. Одне фото після 22:00, а не після кожної години.\n\n"
                . "<b>Чому це важливо?</b>\n"
                . "Щоб усі сусіди залишали альтанку у належному стані. Якщо хтось залишив сміття — на фото буде видно, хто був останнім.\n\n"
                . "<b>Як надіслати?</b>\n"
                . "Просто надішліть фото у цей чат після того, як прибрали за собою. Бот сам прив'яже його до вашої сесії.\n\n"
                . "<b>Якщо не надішлете:</b>\n"
                . "Бот нагадає 3 рази (через 20, 40 і 60 хв після закінчення сесії). Якщо за 80 хв фото не буде — аккаунт буде заблоковано. Розблокувати може лише бухгалтер.",
        ],
        'history' => [
            'title' => '📜 Історія бронювань',
            'body' => "<b>📜 Історія бронювань</b>\n\n"
                . "Натисніть кнопку <b>«Історія бронювань»</b> у головному меню, щоб переглянути, хто бронював альтанки за останні 30 днів.\n\n"
                . "Поряд з кожним записом видно статус:\n"
                . "• 📷 — фото завантажено\n"
                . "• ⏳ — очікується завантаження\n"
                . "• ⛔ — пропущено / прострочено\n"
                . "• без значка — старі бронювання до запуску фото-вимоги",
        ],
        'contacts' => [
            'title' => '📞 Контакти',
            'body' => "<b>📞 Контакти</b>\n\n"
                . "Бухгалтер ОСББ: <b>Аліна</b> — <b>+380 93 658 32 02</b>\n"
                . "(активація аккаунту, розблокування, заборгованість)\n\n"
                . "Технічні питання з ботом: <b>Іван</b> — <b>+380 63 302 26 66</b>",
        ],
    ];

    public function __invoke(Nutgram $bot): void
    {
        $data = $bot->isCallbackQuery() ? ($bot->callbackQuery()->data ?? '') : '';

        if (str_starts_with($data, 'info-topic:')) {
            $key = substr($data, strlen('info-topic:'));
            $this->renderTopic($bot, $key);
            return;
        }

        $this->renderMenu($bot, $data === self::MENU_CALLBACK);
    }

    private function renderMenu(Nutgram $bot, bool $edit): void
    {
        $text = "ℹ️ <b>Інструкція та FAQ</b>\n\nОберіть тему, щоб дізнатися більше:";

        $markup = InlineKeyboardMarkup::make();
        foreach (self::TOPICS as $key => $topic) {
            $markup->addRow(
                InlineKeyboardButton::make($topic['title'], callback_data: 'info-topic:' . $key)
            );
        }
        $markup->addRow(StartCommand::homeButton());

        if ($edit) {
            try {
                $bot->editMessageText(text: $text, parse_mode: ParseMode::HTML, reply_markup: $markup);
                return;
            } catch (\Throwable) {
                // fall through to a fresh message
            }
        }

        $bot->sendMessage(text: $text, parse_mode: ParseMode::HTML, reply_markup: $markup);
    }

    private function renderTopic(Nutgram $bot, string $key): void
    {
        $topic = self::TOPICS[$key] ?? null;
        if (!$topic) {
            $this->renderMenu($bot, true);
            return;
        }

        $markup = InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make('⬅️ До списку тем', callback_data: self::MENU_CALLBACK)
            )
            ->addRow(StartCommand::homeButton());

        try {
            $bot->editMessageText(text: $topic['body'], parse_mode: ParseMode::HTML, reply_markup: $markup);
        } catch (\Throwable) {
            $bot->sendMessage(text: $topic['body'], parse_mode: ParseMode::HTML, reply_markup: $markup);
        }
    }
}
