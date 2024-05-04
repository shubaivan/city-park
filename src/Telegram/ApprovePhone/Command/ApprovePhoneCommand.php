<?php

namespace App\Telegram\ApprovePhone\Command;

use SergiX44\Nutgram\Handlers\Type\Command;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\Keyboard\KeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\ReplyKeyboardMarkup;

class ApprovePhoneCommand extends Command
{
    protected string $command = 'phone';
    protected ?string $description = 'Підтвердіть ВАШ телефон';

    public function handle(Nutgram $bot): void
    {
        $bot->sendMessage(
            text: 'Подтрібно натиснути',
            reply_markup: ReplyKeyboardMarkup::make(one_time_keyboard: true)->addRow(
                KeyboardButton::make('Підтвердіть ВАШ телефон', true),
            )
        );
    }
}