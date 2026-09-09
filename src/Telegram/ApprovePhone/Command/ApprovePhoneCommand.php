<?php

namespace App\Telegram\ApprovePhone\Command;

use SergiX44\Nutgram\Handlers\Type\Command;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\Keyboard\KeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\ReplyKeyboardMarkup;

/**
 * The door: a resident tells the bot which number they are, and the bot finds their flat.
 *
 * This is the first screen almost everybody sees, and it said «Подтрібно натиснути» over a
 * button shouting «Підтвердіть ВАШ телефон» — half a Russian word, no explanation of why a
 * bot is asking for a phone number, and capitals that read as an order. A person whose
 * number is in the ОСББ registry has nothing to lose by pressing it; a person who cannot
 * tell what it is for closes the bot.
 */
class ApprovePhoneCommand extends Command
{
    protected string $command = 'phone';
    protected ?string $description = '📱 Підтвердити номер телефону';

    public function handle(Nutgram $bot): void
    {
        $bot->sendMessage(
            text: "📱 <b>Підтвердження номера телефону</b>\n\n"
                . "Натисніть кнопку внизу — Telegram надішле ваш номер, і бот знайде вашу "
                . "квартиру в реєстрі ОСББ.\n\n"
                . '<i>Номер уже є в ОСББ — ви давали його для нарахувань. Бот нікому його '
                . 'не показує: він потрібен лише для того, щоб упізнати вас.</i>',
            parse_mode: ParseMode::HTML,
            reply_markup: ReplyKeyboardMarkup::make(one_time_keyboard: true, resize_keyboard: true)->addRow(
                KeyboardButton::make('📱 Поділитися номером телефону', true),
            )
        );
    }
}