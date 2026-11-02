<?php

namespace App\Telegram\Rental\Command;

use App\Service\RentalVideoHintText;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

/**
 * A video sent to the bot outside any conversation. Without this it got no answer at all,
 * which reads as the bot being down. A document that is not a video stays unanswered, as
 * it always has been.
 */
class RentalVideoHint
{
    public function __invoke(Nutgram $bot): void
    {
        $text = match (RentalVideo::incomingKind($bot)) {
            RentalVideo::KIND_VIDEO => RentalVideoHintText::OUTSIDE,
            RentalVideo::KIND_NOTE => RentalVideo::NOTE_REFUSED,
            RentalVideo::KIND_FILE => RentalVideo::FILE_REFUSED,
            default => null,
        };

        if ($text === null || RentalVideo::isRestOfAlbum($bot)) {
            return;
        }

        $bot->sendMessage(
            text: $text,
            reply_markup: InlineKeyboardMarkup::make()->addRow(
                InlineKeyboardButton::make('🔑 Оренда та продаж', callback_data: RentalMenuCommand::MENU_CALLBACK),
            ),
        );
    }
}
