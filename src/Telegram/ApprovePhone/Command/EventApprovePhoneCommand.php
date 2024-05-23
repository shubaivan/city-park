<?php

namespace App\Telegram\ApprovePhone\Command;

use App\Service\TelegramUserService;
use App\Telegram\Location\Repository\OfficeRepository;
use Doctrine\ORM\EntityManagerInterface;
use SergiX44\Nutgram\Handlers\Type\Command;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\Keyboard\KeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\ReplyKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\Keyboard\ReplyKeyboardRemove;

class EventApprovePhoneCommand extends Command
{
    protected string $command = 'eventContact';
    protected ?string $description = 'Підтвердіть ВАШ телефон';

    public function __construct(
        private TelegramUserService $telegramUserService,
        private EntityManagerInterface $em,
        $callable = null, ?string $command = null)
    {
        parent::__construct($callable, $command);
    }

    public function handle(Nutgram $bot): void
    {
        $this->telegramUserService->savePhone($bot->message()->contact->phone_number);
        $this->em->flush();

        $bot->sendMessage(
            text: 'Підтверджено, дякуюмо, тепер можете бронювати',
        );
        $bot->sendMessage(
            text: 'Removing keyboard...',
            reply_markup: ReplyKeyboardRemove::make(true),
        )?->delete();
    }
}