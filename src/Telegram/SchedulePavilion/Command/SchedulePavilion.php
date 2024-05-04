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

class SchedulePavilion extends Conversation
{
    protected ?string $step = 'choosePavilion';
    public ?string $pavilion;
    public ?string $month;
    public ?string $day;
    public ?string $hour;

    public function __construct(
        private SchedulePavilionService $schedulePavilionService,
        private EntityManagerInterface $em,
        private TelegramUserService $telegramUserService,
        private ValidatorInterface $validator
    ) {}

    public function choosePavilion(Nutgram $bot)
    {
        $bot->sendMessage(
            text: 'Оберіть альтанку',
            reply_markup: InlineKeyboardMarkup::make()
                ->addRow(InlineKeyboardButton::make('Перша', callback_data: '1'), InlineKeyboardButton::make('Друга', callback_data: '2'))
        );
        $this->next('chooseMonth');
    }

    public function chooseMonth(Nutgram $bot)
    {
        if (!$bot->isCallbackQuery()) {
            $this->choosePavilion($bot);

            return;
        }
        $this->pavilion = $bot->callbackQuery()->data;
        $bot->sendMessage(
            text: 'Альтанка №' . $this->pavilion
        );
        $current = new \DateTime();
        $currentMonth = (int)$current->format('m');
        $last = (clone $current)->modify('last day of december this year');
        $lastMonth = (int)$last->format('m');

        $inlineKeyboardMarkup = InlineKeyboardMarkup::make();
        $month = [];
        for ($i = $currentMonth; $i<=$lastMonth; $i++) {
            if ($i == $currentMonth) {
                $format = $current->format('Y-m');
            } else {
                $format = $current->modify('+1 month')->format('Y-m');
            }
            $month[] = InlineKeyboardButton::make(text: $format, callback_data: $current->format('m'));
            if (count($month) == 3) {
                $inlineKeyboardMarkup->addRow(...$month);
                $month = [];
            }
        }
        if (count($month)) {
            $inlineKeyboardMarkup->addRow(...$month);
        }
        $inlineKeyboardMarkup->addRow(InlineKeyboardButton::make(text: 'На початок', callback_data: 0));
        $bot->sendMessage(
            text: 'Оберіть місяць',
            reply_markup: $inlineKeyboardMarkup,
        );

        $this->next('chooseDay');
    }

    public function chooseDay(Nutgram $bot)
    {
        if (!$bot->isCallbackQuery() || $bot->callbackQuery()->data == "0") {
            $this->choosePavilion($bot);

            return;
        }
        $this->month = $bot->callbackQuery()->data;
        $bot->sendMessage(
            text: 'Місяць ' . $this->month
        );
        $current = new \DateTime();
        $currentDay = (int)$current->format('d');
        $last = (clone $current)->modify('last day of');
        $lastDay = (int)$last->format('d');

        $inlineKeyboardMarkup = InlineKeyboardMarkup::make();
        $days = [];
        for ($i = $currentDay; $i<=$lastDay; $i++) {
            if ($i == $currentDay) {
                $format = $current->format('F-d');
            } else {
                $format = $current->modify('+1 day')->format('F-d');
            }
            $days[] = InlineKeyboardButton::make(text: $format, callback_data: $current->format('d'));
            if (count($days) == 5) {
                $inlineKeyboardMarkup->addRow(...$days);
                $days = [];
            }
        }
        if (count($days)) {
            $inlineKeyboardMarkup->addRow(...$days);
        }
        $inlineKeyboardMarkup->addRow(InlineKeyboardButton::make(text: 'На початок', callback_data: 0));
        $bot->sendMessage(
            text: 'Оберіть день',
            reply_markup: $inlineKeyboardMarkup,
        );

        $this->next('chooseTimeSet');
    }

    public function chooseTimeSet(Nutgram $bot)
    {
        if (!$bot->isCallbackQuery() || $bot->callbackQuery()->data == "0") {
            $this->choosePavilion($bot);

            return;
        }

        $this->day = $bot->callbackQuery()->data;
        $bot->sendMessage(
            text: 'День ' . $this->day
        );

        $current = new \DateTime();

        $scheduledSets = $this->schedulePavilionService->getExistSet(
            (int)$current->format('Y'),
            (int)$this->month,
            (int)$this->day,
        );

        $chosenDate = new \DateTime();
        $chosenDate->setDate((int)$current->format('Y'), (int)$this->month, (int)$this->day);
        $chosenDate->setTime(0,0);
        if ($current->format('Y-m-d') == $chosenDate->format('Y-m-d')) {
            $currentHour = (int)$current->format('H');
            $currentHour += 1;
            $chosenDate->setTime($currentHour, 0);
            $last = 24;
        } else {
            $currentHour = 0;
            $last = 24;
        }

        $inlineKeyboardMarkup = InlineKeyboardMarkup::make();
        $hours = [];
        for ($i = $currentHour; $i<$last; $i++) {
            if (array_key_exists($i, $scheduledSets)) {
                $chosenDate->modify('+1 hour');
                continue;
            } elseif ($i == $currentHour) {
                $format = $chosenDate->format('D/H-i');
                $hours[] = InlineKeyboardButton::make(text: $format, callback_data: $chosenDate->format('H'));
            } else {
                $format = $chosenDate->modify('+1 hour')->format('D/H-i');
                $hours[] = InlineKeyboardButton::make(text: $format, callback_data: $chosenDate->format('H'));
            }

            if (count($hours) == 3) {
                $inlineKeyboardMarkup->addRow(...$hours);
                $hours = [];
            }
        }
        if (count($hours)) {
            $inlineKeyboardMarkup->addRow(...$hours);
        }
        $inlineKeyboardMarkup->addRow(InlineKeyboardButton::make(text: 'На початок', callback_data: 0));
        foreach ($scheduledSets as $key => $set) {
            $bot->sendMessage(
                text: sprintf('годин %s заброньована %s', $key, $set),
            );
        }
        $bot->sendMessage(
            text: 'Оберіть час',
            reply_markup: $inlineKeyboardMarkup,
        );

        $this->next('scheduleDate');
    }

    public function scheduleDate(Nutgram $bot)
    {
        if (!$bot->isCallbackQuery() || $bot->callbackQuery()->data == "0") {
            $this->choosePavilion($bot);

            return;
        }

        $this->hour = $bot->callbackQuery()->data;

        $current = new \DateTime();
        $dateTime = new \DateTime();
        $dateTime->setDate((int)$current->format('Y'), (int)$this->month, (int)$this->day);
        $dateTime->setTime((int)$this->hour,0);
        $bot->sendMessage(
            text: 'Дата: ' . $dateTime->format('Y/m/d H:i') ,
        );
        $bot->sendMessage(
            text: 'Якщо згодні натисніть *Підтверджую*',
            parse_mode: ParseMode::MARKDOWN,
            reply_markup: InlineKeyboardMarkup::make()->addRow(
                InlineKeyboardButton::make(text: 'Підтверджую', callback_data: 1),
                InlineKeyboardButton::make(text: 'На початок', callback_data: 0),
            )
        );

        $this->next('approveDate');
    }

    public function approveDate(Nutgram $bot)
    {
        if (!$bot->isCallbackQuery() || $bot->callbackQuery()->data == "0") {
            $this->choosePavilion($bot);

            return;
        }

        $scheduledSet = (new ScheduledSet())
            ->setTelegramUserId($this->telegramUserService->getCurrentUser())
            ->setYear((int)(new \DateTime())->format('Y'))
            ->setMonth((int)$this->month)
            ->setDay((int)$this->day)
            ->setHour((int)$this->hour)
            ->setPavilion((int)$this->pavilion)
        ;
        $this->em->persist($scheduledSet);
        $lists = $this->validator->validate($scheduledSet);
        if (count($lists)) {
            foreach ($lists as $list) {
                $bot->sendMessage(
                    text: '<b>'.$list->getMessage().'</b>',
                    parse_mode: ParseMode::HTML
                );
                $this->choosePavilion($bot);

                return;
            }
            $bot->sendMessage(
                text: '<b>Сталась помилка.</b>',
                parse_mode: ParseMode::HTML
            );
            $this->choosePavilion($bot);

            return;
        }
        $this->em->flush();

        $bot->sendMessage(
            text: '<b>Вітаємо</b>, чекайте нагадування від бота <tg-emoji emoji-id="5368324170671202286">👍</tg-emoji>',
            parse_mode: ParseMode::HTML
        );

        $this->end();
    }
}