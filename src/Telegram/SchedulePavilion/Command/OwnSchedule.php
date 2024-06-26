<?php

namespace App\Telegram\SchedulePavilion\Command;

use App\Entity\ScheduledSet;
use App\Service\SchedulePavilionService;
use App\Service\TelegramUserService;
use Doctrine\ORM\EntityManagerInterface;
use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use SergiX44\Nutgram\Telegram\Types\Internal\InputFile;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\Message\Message;

class OwnSchedule extends Conversation
{
    protected ?string $step = 'own';

    public ?int $id;

    public function __construct(
        protected string $projectDir,
        private SchedulePavilionService $schedulePavilionService,
        private EntityManagerInterface $em,
        private TelegramUserService $telegramUserService
    ) {}

    public function own(Nutgram $bot)
    {
        $ownSchedule = $this->schedulePavilionService->getOwn(
            $this->telegramUserService->getCurrentUser()
        );

        if (!$ownSchedule) {
            $bot->sendMessage(
                text: '<b>Немає бронювань</b>',
                parse_mode: ParseMode::HTML
            );
            $this->end();

            return;
        }

        $bot->sendMessage(
            text: sprintf('<b>Ващі</b> бронювання'),
            parse_mode: ParseMode::HTML
        );

        foreach ($ownSchedule as $pavilion => $setSchedule) {
            $file = sprintf(
                '%s/assets/img/pavilion%s',
                $this->projectDir,
                $pavilion
            );
            if (is_file($file) && is_readable($file)) {
                $photo = fopen($file, 'r+');

                /** @var Message $message */
                $message = $bot->sendPhoto(
                    photo: InputFile::make($photo)
                );
            }
            /** @var ScheduledSet $set */
            foreach ($setSchedule as $set) {
                $key = strlen($set->getHour()) == 1 ? '0' . $set->getHour() : $set->getHour();

                $bot->sendMessage(
                    text: sprintf('альтанка №:%s, час:%s', $set->getPavilion(), $set->getScheduledDateTime()->format('Y/m/d H:i')),
                    parse_mode: ParseMode::HTML,
                    reply_markup: InlineKeyboardMarkup::make()
                        ->addRow(
                            InlineKeyboardButton::make(
                                'Відмінити', callback_data: 'decline_' . $set->getId()
                            ),
                        )
                );
            }
        }

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
            text: sprintf('альтанка №:%s, час:%s', $scheduledSet->getPavilion(), $scheduledSet->getScheduledDateTime()->format('Y/m/d H:i')),
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