<?php

namespace App\Telegram\Start\Command;

use SergiX44\Nutgram\Handlers\Type\Command;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

class StartCommand extends Command
{
    protected string $command = 'start';
    protected ?string $description = 'Початок спілкування';

    public const MAIN_MENU_CALLBACK = 'main-menu';

    public function handle(Nutgram $bot): void
    {
        self::send($bot, edit: false);
    }

    public function __invoke(Nutgram $bot): mixed
    {
        $edit = $bot->isCallbackQuery();
        self::send($bot, edit: $edit);
        return null;
    }

    public static function send(Nutgram $bot, bool $edit = false): void
    {
        $text = 'Оберіть:';
        $markup = self::mainMenuMarkup();

        if ($edit) {
            try {
                $bot->editMessageText(text: $text, parse_mode: ParseMode::HTML, reply_markup: $markup);
                return;
            } catch (\Throwable) {
                // fall through to a new message
            }
        }

        $bot->sendMessage(text: $text, parse_mode: ParseMode::HTML, reply_markup: $markup);
    }

    public static function homeButton(): InlineKeyboardButton
    {
        return InlineKeyboardButton::make('🏠 На головну', callback_data: self::MAIN_MENU_CALLBACK);
    }

    private static function mainMenuMarkup(): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make('Бронювання', callback_data: 'schedule-pavilion'),
                InlineKeyboardButton::make('Переглянути свої', callback_data: 'own-schedule'),
                InlineKeyboardButton::make('Як доїхати?', callback_data: 'type:route'),
            )
            ->addRow(
                InlineKeyboardButton::make('📜 Історія бронювань', callback_data: 'booking-history'),
                InlineKeyboardButton::make('📸 Завантажити фото', callback_data: 'photo-upload-info'),
            )
            ->addRow(
                InlineKeyboardButton::make('ℹ️ Інструкція та FAQ', callback_data: 'info-menu'),
                InlineKeyboardButton::make('🗳️ Голосування', callback_data: 'voting-menu'),
            );
    }
}
