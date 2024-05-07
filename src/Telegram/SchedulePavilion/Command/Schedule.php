<?php

namespace App\Telegram\SchedulePavilion\Command;

use App\Entity\ScheduledSet;
use App\Service\SchedulePavilionService;
use App\Service\TelegramUserService;
use Doctrine\ORM\EntityManagerInterface;
use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Handlers\Type\Command;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

class Schedule extends Command
{
    protected string $command = 'schedule';
    protected ?string $description = 'Бронювання';

    public function handle(Nutgram $bot): void
    {
        $bot->sendMessage(
            text: 'Бронювання:',
            reply_markup: InlineKeyboardMarkup::make()->addRow(
                InlineKeyboardButton::make('Бронювання альтанки', callback_data: 'schedule-pavilion'),
                InlineKeyboardButton::make('Переглянути свої', callback_data: 'own-schedule'),
            )
        );
    }
}