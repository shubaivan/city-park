<?php

namespace App\Telegram\Start\Command;

use SergiX44\Nutgram\Handlers\Type\Command;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

class StartCommand extends Command
{
    protected string $command = 'start';
    protected ?string $description = 'Початок спілкування';

    public function handle(Nutgram $bot): void
    {
        $bot->sendMessage(
            text: 'Оберіть:',
            reply_markup: InlineKeyboardMarkup::make()->addRow(
                InlineKeyboardButton::make('Бронювання альтанки', callback_data: 'schedule-pavilion'),
                InlineKeyboardButton::make('Переглянути свої', callback_data: 'own-schedule'),
                InlineKeyboardButton::make('Як доїхати?', callback_data: 'type:route'),
            )
        );
    }
}