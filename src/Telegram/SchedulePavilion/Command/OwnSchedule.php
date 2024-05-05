<?php

namespace App\Telegram\SchedulePavilion\Command;

use App\Entity\ScheduledSet;
use App\Service\SchedulePavilionService;
use App\Service\TelegramUserService;
use Doctrine\ORM\EntityManagerInterface;
use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class OwnSchedule extends Conversation
{
    protected ?string $step = 'own';

    public ?int $id;

    public function __construct(
        private SchedulePavilionService $schedulePavilionService,
        private EntityManagerInterface $em,
        private TelegramUserService $telegramUserService
    ) {}

    public function own(Nutgram $bot)
    {
        $scheduledSets = $this->schedulePavilionService->getOwn(
            $this->telegramUserService->getCurrentUser()
        );
        $availableDecline = [];
        if (!$scheduledSets) {
            $bot->sendMessage(
                text: '<b>Немає бронювань</b>',
                parse_mode: ParseMode::HTML
            );
            $this->end();
        }
        foreach ($scheduledSets as $set) {
            $scheduledByCurrentUserDate = $set->getScheduledDateTime();

            $availableDecline[] = InlineKeyboardButton::make(
                text: 'Відмінити: ' . $scheduledByCurrentUserDate->format('Y/m/d H:i:s'),
                callback_data: 'decline_' . $set->getId()
            );
        }
        $scheduledInlineKeyboardMarkup = InlineKeyboardMarkup::make();

        $declineHours = [];
        foreach ($availableDecline as $decline) {
            $declineHours[] = $decline;
            if (count($declineHours) == 1) {
                $scheduledInlineKeyboardMarkup->addRow(...$declineHours);
                $declineHours = [];
            }
        }

        if (count($declineHours)) {
            $scheduledInlineKeyboardMarkup->addRow(...$declineHours);
        }

        $bot->sendMessage(
            text: 'Ваші бронювання:',
            reply_markup: $scheduledInlineKeyboardMarkup
        );

        $this->next('scheduleDate');
    }

    public function scheduleDate(Nutgram $bot)
    {
        if (!$bot->isCallbackQuery()
            || $bot->callbackQuery()->data == "0"
            || !str_contains($bot->callbackQuery()->data, 'decline_')
        ) {
            $this->own($bot);

            return;
        }
        $this->id = str_replace('decline_', '', $bot->callbackQuery()->data);
        $scheduledSet = $this->schedulePavilionService->getById($this->id);
        if (!$scheduledSet) {
            $bot->sendMessage(
                text: '<b>Немає бронювань</b>',
                parse_mode: ParseMode::HTML
            );
            $this->end();
        }
        $bot->sendMessage(
            text: 'Дата: ' . $scheduledSet->getScheduledDateTime()->format('Y/m/d H:i') ,
        );
        $bot->sendMessage(
            text: 'Видалити бронювання? Натисніть *Підтверджую*',
            parse_mode: ParseMode::MARKDOWN,
            reply_markup: InlineKeyboardMarkup::make()->addRow(
                InlineKeyboardButton::make(text: 'Підтверджую', callback_data: 1),
                InlineKeyboardButton::make(text: 'На початок', callback_data: 0),
            )
        );

        $this->next('removeScheduled');
    }

    public function removeScheduled(Nutgram $bot)
    {
        if (!$bot->isCallbackQuery()
            || $bot->callbackQuery()->data != "1"
        ) {
            $this->own($bot);

            return;
        }

        $scheduledSet = $this->schedulePavilionService->getById($this->id);
        if (!$scheduledSet) {
            $bot->sendMessage(
                text: '<b>Немає бронювань</b>',
                parse_mode: ParseMode::HTML
            );
            $this->end();
        }

        $this->em->remove($scheduledSet);
        $this->em->flush();

        $bot->sendMessage(
            text: '<b>Видалено</b>',
            parse_mode: ParseMode::HTML
        );

        $this->id = null;

        $this->own($bot);
    }
}