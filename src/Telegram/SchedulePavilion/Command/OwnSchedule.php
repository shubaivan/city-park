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
                text: '📋 <b>У вас немає активних бронювань</b>',
                parse_mode: ParseMode::HTML
            );
            $this->end();

            return;
        }

        $textParts = ['📋 <b>Ваші бронювання:</b>', ''];

        $inlineKeyboardMarkup = InlineKeyboardMarkup::make();

        foreach ($ownSchedule as $pavilion => $setSchedule) {
            $pavilionName = $pavilion == '1' ? 'Перша' : 'Друга';
            $textParts[] = '🏠 <b>Альтанка: ' . $pavilionName . '</b>';

            /** @var ScheduledSet $set */
            foreach ($setSchedule as $set) {
                $dateTime = $set->getScheduledDateTime();
                $textParts[] = sprintf(
                    '   📅 %s  ⏰ %s',
                    $dateTime->format('d.m.Y'),
                    $dateTime->format('H:i')
                );

                $inlineKeyboardMarkup->addRow(
                    InlineKeyboardButton::make(
                        '❌ Скасувати ' . $dateTime->format('d.m H:i') . ' (Альт. ' . $pavilion . ')',
                        callback_data: 'decline_' . $set->getId()
                    ),
                );
            }
            $textParts[] = '';
        }

        $bot->sendMessage(
            text: implode("\n", $textParts),
            parse_mode: ParseMode::HTML,
            reply_markup: $inlineKeyboardMarkup,
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
            $bot->editMessageText(
                text: '📋 <b>Бронювання не знайдено</b>',
                parse_mode: ParseMode::HTML
            );
            $this->end();

            return;
        }

        $dateTime = $scheduledSet->getScheduledDateTime();
        $pavilionName = $scheduledSet->getPavilion() == 1 ? 'Перша' : 'Друга';

        $bot->editMessageText(
            text: sprintf(
                "Видалити бронювання?\n\n🏠 Альтанка: <b>%s</b>\n📅 Дата: <b>%s</b>\n⏰ Час: <b>%s</b>",
                $pavilionName,
                $dateTime->format('d.m.Y'),
                $dateTime->format('H:i')
            ),
            parse_mode: ParseMode::HTML,
            reply_markup: InlineKeyboardMarkup::make()->addRow(
                InlineKeyboardButton::make(text: '✅ Підтверджую', callback_data: 1),
                InlineKeyboardButton::make(text: '⬅️ Назад', callback_data: 0),
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
            $bot->editMessageText(
                text: '📋 <b>Бронювання не знайдено</b>',
                parse_mode: ParseMode::HTML
            );
            $this->end();

            return;
        }

        $this->em->remove($scheduledSet);
        $this->em->flush();

        $bot->editMessageText(
            text: '✅ <b>Бронювання видалено</b>',
            parse_mode: ParseMode::HTML
        );

        $this->id = null;

        $this->end();
    }
}
